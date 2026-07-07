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

class Index implements HttpGetActionInterface
{
    private const XML_INDEXNOW_ENABLED = 'panth_index_now/indexnow/enabled';
    private const XML_INDEXNOW_API_KEY = 'panth_index_now/indexnow/api_key';

    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request
    ) {
    }

    public function execute(): ResponseInterface|ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $apiKey  = $this->getApiKey($storeId);

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $result->setHeader('Cache-Control', 'no-store, max-age=0', true);

        if ($apiKey === '' || !$this->isEnabled($storeId)) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        $requestedKey = $this->extractRequestedKey();
        if ($requestedKey !== null && !hash_equals(strtolower($apiKey), strtolower($requestedKey))) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        $result->setContents($apiKey);
        return $result;
    }

    private function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_INDEXNOW_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function getApiKey(int $storeId): string
    {
        $raw = $this->scopeConfig->getValue(
            self::XML_INDEXNOW_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return trim((string) ($raw ?? ''));
    }

    private function extractRequestedKey(): ?string
    {
        $key = (string) $this->request->getParam('key', '');
        if ($key === '') {
            return null;
        }
        return (string) preg_replace('/\.txt$/i', '', $key);
    }
}
