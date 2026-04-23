<?php
declare(strict_types=1);

namespace Panth\IndexNow\Observer\IndexNow;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\IndexNow\Model\IndexNow\Submitter;
use Psr\Log\LoggerInterface;

/**
 * Listens to catalog_product_save_after, catalog_category_save_after and
 * cms_page_save_after, collecting changed entity URLs for bulk IndexNow
 * submission at the end of the PHP request lifecycle.
 */
class EntityChangeObserver implements ObserverInterface
{
    private const XML_INDEXNOW_ENABLED = 'panth_index_now/indexnow/enabled';

    /**
     * Accumulated URLs keyed by store ID. Flushed once in the shutdown hook.
     *
     * @var array<int, string[]>
     */
    private static array $pendingUrls = [];

    /**
     * @var bool
     */
    private static bool $shutdownRegistered = false;

    /**
     * @var Submitter|null
     */
    private static ?Submitter $submitterRef = null;

    /**
     * @var LoggerInterface|null
     */
    private static ?LoggerInterface $loggerRef = null;

    /**
     * @param ScopeConfigInterface  $scopeConfig
     * @param Submitter             $submitter
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface       $logger
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Submitter $submitter,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
        // Keep static references so the shutdown function can flush.
        self::$submitterRef = $this->submitter;
        self::$loggerRef    = $this->logger;
    }

    /**
     * Collect the changed entity URL for later IndexNow submission.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();

        // Determine which entity was saved and its store context.
        $storeId = null;
        $url     = null;

        if ($product = $event->getData('product')) {
            /** @var Product $product */
            if (!$product->getId()) {
                return;
            }
            $storeId = (int) $product->getStoreId();
            if (!$this->isIndexNowEnabled($storeId)) {
                return;
            }
            $url = $this->getProductUrl($product, $storeId);
        } elseif ($category = $event->getData('category')) {
            /** @var Category $category */
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

    /**
     * Flush all collected URLs to IndexNow. Called automatically via
     * register_shutdown_function at the end of the request.
     *
     * @return void
     */
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

    /**
     * Is IndexNow submission enabled for the given store?
     *
     * @param int $storeId
     * @return bool
     */
    private function isIndexNowEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_INDEXNOW_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Resolve the full product URL in the given store context.
     *
     * @param Product $product
     * @param int     $storeId
     * @return string
     */
    private function getProductUrl(Product $product, int $storeId): string
    {
        try {
            $product->setStoreId($storeId);
            return (string) $product->getProductUrl();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Build the CMS page URL from its identifier and the store base URL.
     *
     * @param Page $page
     * @param int  $storeId
     * @return string
     */
    private function getCmsPageUrl(Page $page, int $storeId): string
    {
        try {
            $store      = $this->storeManager->getStore($storeId);
            $baseUrl    = rtrim((string) $store->getBaseUrl(), '/');
            $identifier = $page->getIdentifier();
            return $baseUrl . '/' . ltrim($identifier, '/');
        } catch (\Throwable) {
            return '';
        }
    }
}
