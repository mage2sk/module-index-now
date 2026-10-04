<?php
declare(strict_types=1);

namespace Panth\IndexNow\Model\IndexNow;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Submitter
{
    public const KEY_PATTERN = '/^[a-zA-Z0-9-]{8,128}$/';

    private const ENDPOINT = 'https://api.indexnow.org/IndexNow';
    private const MAX_BATCH_SIZE = 10000;
    public const TIMEOUT = 15;
    public const REQUEST_TIMEOUT = 4;
    private const CONNECT_TIMEOUT = 5;

    private const XML_INDEXNOW_ENABLED = 'panth_index_now/indexnow/enabled';
    private const XML_INDEXNOW_API_KEY = 'panth_index_now/indexnow/api_key';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    public function submit(array $urls, int $storeId, int $timeout = self::TIMEOUT): bool
    {
        return $this->submitByStore([$storeId => $urls], $timeout);
    }

    public function submitByStore(array $urlsByStore, int $timeout = self::TIMEOUT): bool
    {
        $groups = [];
        $success = true;

        foreach ($urlsByStore as $storeId => $urls) {
            if (array_filter((array) $urls) === []) {
                continue;
            }
            $target = $this->resolveTarget((int) $storeId);
            if ($target === null) {
                $success = false;
                continue;
            }
            $urls = $this->filterUrls((array) $urls, $target['host'], $target['path']);
            if ($urls === []) {
                continue;
            }
            $groupKey = $target['host'] . "\n" . $target['key'] . "\n" . $target['keyLocation'];
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = $target + ['urls' => []];
            }
            array_push($groups[$groupKey]['urls'], ...$urls);
        }

        foreach ($groups as $group) {
            $urls = array_values(array_unique($group['urls']));
            foreach (array_chunk($urls, self::MAX_BATCH_SIZE) as $batch) {
                if (!$this->sendBatch($batch, $group['host'], $group['key'], $group['keyLocation'], $timeout)) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    private function resolveTarget(int $storeId): ?array
    {
        try {
            if (!$this->scopeConfig->isSetFlag(self::XML_INDEXNOW_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
                return null;
            }

            $apiKey = $this->getApiKey($storeId);
            if ($apiKey === '') {
                $this->logger->warning('Panth IndexNow: API key is not configured; skipping submission.');
                return null;
            }
            if (!self::isValidKey($apiKey)) {
                $this->logger->warning('Panth IndexNow: API key has an invalid format; skipping submission.');
                return null;
            }

            $store = $this->storeManager->getStore($storeId);
            $baseUrl = rtrim(
                (string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, $store->isFrontUrlSecure()),
                '/'
            );
        } catch (\Throwable $e) {
            $this->logger->error('Panth IndexNow: cannot resolve store.', ['error' => $e->getMessage()]);
            return null;
        }

        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        return [
            'host'        => $host,
            'path'        => rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/') . '/',
            'key'         => $apiKey,
            'keyLocation' => $baseUrl . '/' . $apiKey . '.txt',
        ];
    }

    private function filterUrls(array $urls, string $host, string $path): array
    {
        $result = [];
        foreach ($urls as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $parts = parse_url($url);
            if (!is_array($parts)
                || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                || strtolower((string) ($parts['host'] ?? '')) !== $host
                || isset($parts['user'])
                || isset($parts['pass'])
                || !str_starts_with((string) ($parts['path'] ?? '/'), $path)
            ) {
                continue;
            }
            $result[] = $url;
        }
        return array_values(array_unique($result));
    }

    private function sendBatch(
        array $urls,
        string $host,
        string $apiKey,
        string $keyLocation,
        int $timeout
    ): bool {
        $timeout = max(1, min($timeout, self::TIMEOUT));
        $payload = [
            'host'        => $host,
            'key'         => $apiKey,
            'keyLocation' => $keyLocation,
            'urlList'     => $urls,
        ];

        try {
            $curl = $this->curlFactory->create();
            $curl->addHeader('Content-Type', 'application/json; charset=utf-8');
            $curl->setOption(CURLOPT_TIMEOUT, $timeout);
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, min(self::CONNECT_TIMEOUT, max(1, intdiv($timeout, 2))));
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
                'body'   => substr((string) $curl->getBody(), 0, 500),
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
