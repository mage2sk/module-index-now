<?php
declare(strict_types=1);

namespace Panth\IndexNow\Observer\IndexNow;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Helper\Page as CmsPageHelper;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\IndexNow\Model\IndexNow\Submitter;
use Psr\Log\LoggerInterface;

class EntityChangeObserver implements ObserverInterface
{
    private const XML_INDEXNOW_ENABLED = 'panth_index_now/indexnow/enabled';

    private static array $pendingUrls = [];

    private static bool $shutdownRegistered = false;

    private static ?Submitter $submitterRef = null;

    private static ?LoggerInterface $loggerRef = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Submitter $submitter,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly CmsPageHelper $cmsPageHelper,
        private readonly AppEmulation $appEmulation
    ) {
        self::$submitterRef = $this->submitter;
        self::$loggerRef    = $this->logger;
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();

        $storeId = null;
        $url     = null;

        if ($product = $event->getData('product')) {
            if (!$product->getId()) {
                return;
            }
            $storeId = (int) $product->getStoreId();
            if (!$this->isIndexNowEnabled($storeId)) {
                return;
            }
            $url = $this->getProductUrl($product, $storeId);
        } elseif ($category = $event->getData('category')) {
            if (!$category->getId()) {
                return;
            }
            $storeId = (int) $category->getStoreId();
            if (!$this->isIndexNowEnabled($storeId)) {
                return;
            }
            $url = $category->getUrl();
        } elseif (($page = $event->getData('object')) && $page instanceof Page) {
            if (!$page->getId()) {
                return;
            }
            $storeIds = $page->getStoreId();
            $storeId  = is_array($storeIds) ? (int) ($storeIds[0] ?? 0) : (int) $storeIds;
            if ($storeId === 0) {
                $storeId = (int) $this->storeManager->getDefaultStoreView()?->getId();
            }
            if (!$this->isIndexNowEnabled($storeId)) {
                return;
            }
            $url = $this->getCmsPageUrl($page, $storeId);
        }

        if ($url === null || $url === '' || $storeId === null) {
            return;
        }

        self::$pendingUrls[$storeId][] = $url;

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'flushPendingUrls']);
        }
    }

    public static function flushPendingUrls(): void
    {
        if (self::$submitterRef === null) {
            return;
        }

        foreach (self::$pendingUrls as $storeId => $urls) {
            $urls = array_values(array_unique($urls));
            if ($urls === []) {
                continue;
            }
            try {
                self::$submitterRef->submit($urls, $storeId);
            } catch (\Throwable $e) {
                self::$loggerRef?->error('Panth IndexNow flush failed.', [
                    'error'   => $e->getMessage(),
                    'storeId' => $storeId,
                ]);
            }
        }

        self::$pendingUrls = [];
    }

    private function isIndexNowEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_INDEXNOW_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function getProductUrl(Product $product, int $storeId): string
    {
        try {
            $product->setStoreId($storeId);
            return (string) $product->getProductUrl();
        } catch (\Throwable) {
            return '';
        }
    }

    private function getCmsPageUrl(Page $page, int $storeId): string
    {
        $pageId = (int) $page->getId();
        if ($pageId <= 0) {
            return '';
        }

        try {
            $this->appEmulation->startEnvironmentEmulation(
                $storeId,
                \Magento\Framework\App\Area::AREA_FRONTEND,
                true
            );
            try {
                $url = (string) $this->cmsPageHelper->getPageUrl($pageId);
            } finally {
                $this->appEmulation->stopEnvironmentEmulation();
            }

            if ($url !== '') {
                return $url;
            }

            $store      = $this->storeManager->getStore($storeId);
            $baseUrl    = rtrim((string) $store->getBaseUrl(), '/');
            $identifier = (string) $page->getIdentifier();
            return $identifier !== ''
                ? $baseUrl . '/' . ltrim($identifier, '/')
                : '';
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
