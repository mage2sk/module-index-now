<?php
declare(strict_types=1);

namespace Panth\IndexNow\Observer\IndexNow;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\UrlFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\IndexNow\Model\IndexNow\Queue;
use Panth\IndexNow\Model\IndexNow\Submitter;
use Psr\Log\LoggerInterface;

class EntityChangeObserver implements ObserverInterface
{
    private const XML_INDEXNOW_ENABLED = 'panth_index_now/indexnow/enabled';
    private const XML_SUBMIT_DELETIONS = 'panth_index_now/indexnow/submit_deletions';
    private const XML_CMS_HOME_PAGE = 'web/default/cms_home_page';
    private const DELETE_EVENT = 'model_delete_before';

    private static array $pendingIds = [];

    private static bool $shutdownRegistered = false;

    private static ?Queue $queueRef = null;

    private static ?LoggerInterface $loggerRef = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly CategoryFactory $categoryFactory,
        private readonly UrlFactory $urlFactory,
        private readonly Queue $queue
    ) {
        self::$queueRef  = $this->queue;
        self::$loggerRef = $this->logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $this->collect($observer);
        } catch (\Throwable $e) {
            $this->logger->error('Panth IndexNow: cannot collect URL.', ['error' => $e->getMessage()]);
        }
    }

    public static function flushPendingUrls(): void
    {
        if (self::$queueRef === null) {
            return;
        }

        $ids = array_keys(self::$pendingIds);
        self::$pendingIds = [];
        if ($ids === []) {
            return;
        }

        try {
            self::$queueRef->flush($ids, Submitter::REQUEST_TIMEOUT);
        } catch (\Throwable $e) {
            self::$loggerRef?->error('Panth IndexNow flush failed.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function collect(Observer $observer): void
    {
        $event = $observer->getEvent();
        $action = (string) $event->getName() === self::DELETE_EVENT
            ? Queue::ACTION_DELETE
            : Queue::ACTION_UPDATE;
        $product = $action === Queue::ACTION_DELETE ? $event->getData('object') : $event->getData('product');
        $category = $action === Queue::ACTION_DELETE ? $event->getData('object') : $event->getData('category');

        if ($product instanceof Product) {
            if (!$product->getId() || (int) $product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
                return;
            }
            $storeIds = (int) $product->getStoreId() === 0 || $action === Queue::ACTION_DELETE
                ? (array) $product->getStoreIds()
                : [(int) $product->getStoreId()];
            foreach ($this->filterStoreIds($storeIds, $action) as $storeId) {
                $this->queue($storeId, $this->getProductUrl($product, $storeId), $action);
            }
        } elseif ($category instanceof Category) {
            if (!$category->getId() || (int) $category->getLevel() < 2) {
                return;
            }
            $storeIds = (int) $category->getStoreId() === 0 || $action === Queue::ACTION_DELETE
                ? (array) $category->getStoreIds()
                : [(int) $category->getStoreId()];
            foreach ($this->filterStoreIds($storeIds, $action) as $storeId) {
                $this->queue($storeId, $this->getCategoryUrl($category, $storeId), $action);
            }
        } elseif (($page = $event->getData('object')) instanceof Page) {
            if (!$page->getId()) {
                return;
            }
            $storeIds = array_map('intval', (array) $page->getStores());
            if ($storeIds === [] || in_array(0, $storeIds, true)) {
                $storeIds = array_keys($this->storeManager->getStores());
            }
            foreach ($this->filterStoreIds($storeIds, $action) as $storeId) {
                $this->queue($storeId, $this->getCmsPageUrl($page, $storeId), $action);
            }
        }
    }

    private function queue(int $storeId, string $url, string $action): void
    {
        if ($url === '') {
            return;
        }

        $queueId = $this->queue->add($storeId, $url, $action);
        if ($queueId <= 0 || $this->queue->isCronMode()) {
            return;
        }

        self::$pendingIds[$queueId] = true;

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'flushPendingUrls']);
        }
    }

    private function filterStoreIds(array $storeIds, string $action): array
    {
        $result = [];
        foreach (array_unique(array_map('intval', $storeIds)) as $storeId) {
            if ($storeId <= 0) {
                continue;
            }
            try {
                $store = $this->storeManager->getStore($storeId);
            } catch (\Throwable) {
                continue;
            }
            if (!$store->getIsActive() || !$this->isIndexNowEnabled($storeId)) {
                continue;
            }
            if ($action === Queue::ACTION_DELETE && !$this->isSubmitDeletions($storeId)) {
                continue;
            }
            $result[] = $storeId;
        }
        return $result;
    }

    private function isIndexNowEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_INDEXNOW_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function isSubmitDeletions(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_SUBMIT_DELETIONS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function getProductUrl(Product $product, int $storeId): string
    {
        try {
            $copy = clone $product;
            $copy->setStoreId($storeId);
            $copy->unsetData('request_path');
            $copy->unsetData('url_data_object');
            return $this->stripStoreParams((string) $copy->getProductUrl(false));
        } catch (\Throwable) {
            return '';
        }
    }

    private function getCategoryUrl(Category $category, int $storeId): string
    {
        try {
            $copy = $this->categoryFactory->create([
                'url' => $this->urlFactory->create()->setScope($storeId),
            ]);
            $copy->setData($category->getData());
            $copy->setStoreId($storeId);
            $copy->unsetData('url');
            $copy->unsetData('request_path');
            return $this->stripStoreParams((string) $copy->getUrl());
        } catch (\Throwable) {
            return '';
        }
    }

    private function stripStoreParams(string $url): string
    {
        $parts = explode('?', $url, 2);
        if (count($parts) < 2) {
            return $url;
        }
        $query = array_filter(
            explode('&', $parts[1]),
            static function (string $pair): bool {
                $name = urldecode(explode('=', $pair, 2)[0]);
                return $pair !== '' && !in_array($name, ['___store', '___from_store', 'SID'], true);
            }
        );
        return $query === [] ? $parts[0] : $parts[0] . '?' . implode('&', $query);
    }

    private function getCmsPageUrl(Page $page, int $storeId): string
    {
        $pageId = (int) $page->getId();
        if ($pageId <= 0) {
            return '';
        }

        try {
            $identifier = trim((string) $page->getIdentifier(), '/');
            if ($identifier === '') {
                return '';
            }
            $store   = $this->storeManager->getStore($storeId);
            $baseUrl = rtrim(
                (string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, $store->isFrontUrlSecure()),
                '/'
            ) . '/';
            $homePage = (string) $this->scopeConfig->getValue(
                self::XML_CMS_HOME_PAGE,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            return $identifier === explode('|', $homePage)[0] ? $baseUrl : $baseUrl . $identifier;
        } catch (\Throwable $e) {
            $this->logger->warning('Panth IndexNow: CMS URL resolve failed.', [
                'error'   => $e->getMessage(),
                'pageId'  => $pageId,
                'storeId' => $storeId,
            ]);
            return '';
        }
    }
}
