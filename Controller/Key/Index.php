<?php
declare(strict_types=1);

namespace Panth\IndexNow\Controller\Key;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Frontend controller for IndexNow key verification.
 *
 * Route: GET /panth_indexnow/key
 *        GET /panth_indexnow/key?key={api_key}
 *
 * IndexNow protocol requires the declared keyLocation URL to serve the
 * API key as a plain-text response. The Submitter declares keyLocation
 * as "{baseUrl}/panth_indexnow/key" (see
 * Panth\IndexNow\Model\IndexNow\Submitter::submit) so this controller
 * MUST continue to answer that URL and return the configured API key
 * verbatim — Bing, Yandex, Seznam, Naver and Yep fetch it to prove
 * domain ownership.
 *
 * When an explicit "key" parameter is supplied on the query string it
 * must match the configured key exactly (case-insensitive, timing-safe)
 * — mismatches return 404 so the endpoint cannot be used as an
 * arbitrary text echo service.
 */
class Index implements HttpGetActionInterface
{
    private const XML_INDEXNOW_ENABLED = 'panth_index_now/indexnow/enabled';
    private const XML_INDEXNOW_API_KEY = 'panth_index_now/indexnow/api_key';

    /**
     * @param RawFactory            $rawFactory
     * @param ScopeConfigInterface  $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param RequestInterface      $request
     */
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * Serve the IndexNow key file (plain text) for the current store.
     *
     * @return ResponseInterface|ResultInterface
     */
    public function execute(): ResponseInterface|ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $apiKey  = $this->getApiKey($storeId);

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $result->setHeader('Cache-Control', 'no-store, max-age=0', true);

        // Hard-fail when IndexNow is disabled or the key is not configured.
        if ($apiKey === '' || !$this->isEnabled($storeId)) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        // If the caller supplies a key explicitly, require it to match the
        // configured key (case-insensitive, timing-safe).
        $requestedKey = $this->extractRequestedKey();
        if ($requestedKey !== null && !hash_equals(strtolower($apiKey), strtolower($requestedKey))) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        $result->setContents($apiKey);
        return $result;
    }

    /**
     * Is the IndexNow feature enabled for the given store?
     *
     * @param int $storeId
     * @return bool
     */
    private function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_INDEXNOW_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the configured IndexNow API key for the given store.
     *
     * @param int $storeId
     * @return string
     */
    private function getApiKey(int $storeId): string
    {
        $raw = $this->scopeConfig->getValue(
            self::XML_INDEXNOW_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return trim((string) ($raw ?? ''));
    }

    /**
     * Extract an optionally-supplied key from the request query string.
     * Accepts ?key=abc with a trailing `.txt` stripped if present.
     *
     * Returns null when no key was supplied by the caller.
     *
     * @return string|null
     */
    private function extractRequestedKey(): ?string
    {
        $key = (string) $this->request->getParam('key', '');
        if ($key === '') {
            return null;
        }
        return (string) preg_replace('/\.txt$/i', '', $key);
    }
}
