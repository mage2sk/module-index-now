<?php
declare(strict_types=1);

namespace Panth\IndexNow\Model\IndexNow;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Submitter
{
    private const ENDPOINT = 'https://api.indexnow.org/IndexNow';
    private const MAX_BATCH_SIZE = 10000;

    private const XML_INDEXNOW_API_KEY = 'panth_index_now/indexnow/api_key';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function submit(array $urls, int $storeId): bool
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === []) {
            return true;
        }

        $apiKey = $this->getApiKey($storeId);
        if ($apiKey === '') {
            $this->logger->warning('Panth IndexNow: API key is not configured; skipping submission.');
            return false;
        }

        try {
            $store = $this->storeManager->getStore($storeId);
            $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
        } catch (\Throwable $e) {
            $this->logger->error('Panth IndexNow: cannot resolve store.', ['error' => $e->getMessage()]);
            return false;
        }

        $host = (string) parse_url($baseUrl, PHP_URL_HOST);

        $keyLocation = $baseUrl . '/panth_indexnow/key';

        $success = true;
        foreach (array_chunk($urls, self::MAX_BATCH_SIZE) as $batch) {
            if (!$this->sendBatch($batch, $host, $apiKey, $keyLocation)) {
                $success = false;
            }
        }

        return $success;
    }

    private function sendBatch(array $urls, string $host, string $apiKey, string $keyLocation): bool
    {
        $payload = [
            'host'        => $host,
            'key'         => $apiKey,
            'keyLocation' => $keyLocation,
            'urlList'     => $urls,
        ];

        try {
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json; charset=utf-8');
            $curl->setOption(CURLOPT_TIMEOUT, 15);
            $curl->post(self::ENDPOINT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $status = $curl->getStatus();
            if ($status >= 200 && $status < 300) {
                $this->logger->info('Panth IndexNow: submitted ' . count($urls) . ' URL(s).', [
                    'host'   => $host,
                    'status' => $status,
                ]);
                return true;
            }

            $this->logger->warning('Panth IndexNow: unexpected HTTP status.', [
                'status' => $status,
                'body'   => $curl->getBody(),
                'count'  => count($urls),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Panth IndexNow: request failed.', [
                'error' => $e->getMessage(),
                'count' => count($urls),
            ]);
            return false;
        }
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
}
