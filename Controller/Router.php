<?php
declare(strict_types=1);

namespace Panth\IndexNow\Controller;

use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\IndexNow\Model\IndexNow\Submitter;

class Router implements RouterInterface
{
    private const XML_INDEXNOW_ENABLED = 'panth_index_now/indexnow/enabled';
    private const XML_INDEXNOW_API_KEY = 'panth_index_now/indexnow/api_key';

    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if ($request->getModuleName() === 'panth_indexnow') {
            return null;
        }

        $path = trim((string) $request->getPathInfo(), '/');
        if (!preg_match('/^([a-zA-Z0-9-]{8,128})\.txt$/', $path, $matches)) {
            return null;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            return null;
        }

        if (!$this->scopeConfig->isSetFlag(self::XML_INDEXNOW_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return null;
        }

        $apiKey = trim((string) $this->scopeConfig->getValue(
            self::XML_INDEXNOW_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        if (!Submitter::isValidKey($apiKey) || !hash_equals(strtolower($apiKey), strtolower($matches[1]))) {
            return null;
        }

        $request->setModuleName('panth_indexnow')
            ->setControllerName('key')
            ->setActionName('index')
            ->setParam('key', $matches[1]);

        return $this->actionFactory->create(Forward::class);
    }
}
