<?php
declare(strict_types=1);

namespace Panth\IndexNow\Test\Unit\Controller\Key;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\IndexNow\Controller\Key\Index;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    private const KEY = 'AbCd1234-key';

    /** @var array<string, string> */
    private array $headers = [];

    private ?int $code = null;

    private ?string $contents = null;

    private array $configCalls = [];

    private function controller(?string $apiKey, bool $enabled, string $requestedKey = ''): Index
    {
        $raw = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use ($raw) {
            $this->headers[$name] = $value;
            return $raw;
        });
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($raw) {
            $this->code = $code;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function ($contents) use ($raw) {
            $this->contents = $contents;
            return $raw;
        });
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function ($path, $scope = null, $storeId = null) use ($apiKey) {
                $this->configCalls[] = [$path, $scope, $storeId];
                return $apiKey;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn($enabled);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('3');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturn($requestedKey);

        return new Index($rawFactory, $scopeConfig, $storeManager, $request);
    }

    public function testServesTheKeyAsPlainTextWithNoIndexHeaders(): void
    {
        $this->controller('  ' . self::KEY . "\n", true)->execute();

        $this->assertSame(self::KEY, $this->contents);
        $this->assertNull($this->code);
        $this->assertSame('text/plain; charset=utf-8', $this->headers['Content-Type']);
        $this->assertSame(Index::ROBOTS_DIRECTIVE, $this->headers['X-Robots-Tag']);
        $this->assertSame('no-store, max-age=0', $this->headers['Cache-Control']);
        $this->assertSame(
            ['panth_index_now/indexnow/api_key', ScopeInterface::SCOPE_STORE, 3],
            $this->configCalls[0]
        );
    }

    public function testRequestedKeyIsComparedCaseInsensitivelyAndWithoutTxtSuffix(): void
    {
        $this->controller(self::KEY, true, strtolower(self::KEY) . '.TXT')->execute();

        $this->assertSame(self::KEY, $this->contents);
        $this->assertNull($this->code);
    }

    public function testWrongRequestedKeyIsNotFound(): void
    {
        $this->controller(self::KEY, true, 'other-key-123.txt')->execute();

        $this->assertSame(404, $this->code);
        $this->assertSame('', $this->contents);
    }

    #[DataProvider('unavailableProvider')]
    public function testUnavailableKeyIsNotFound(?string $apiKey, bool $enabled): void
    {
        $this->controller($apiKey, $enabled)->execute();

        $this->assertSame(404, $this->code);
        $this->assertSame('', $this->contents);
        $this->assertSame(Index::ROBOTS_DIRECTIVE, $this->headers['X-Robots-Tag']);
    }

    public static function unavailableProvider(): array
    {
        return [
            'null key'  => [null, true],
            'blank key' => ['   ', true],
            'too short' => ['abc123', true],
            'bad chars' => ['abcd_1234/x', true],
            'disabled'  => [self::KEY, false],
        ];
    }
}
