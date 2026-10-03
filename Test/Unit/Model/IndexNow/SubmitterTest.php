<?php
declare(strict_types=1);

namespace Panth\IndexNow\Test\Unit\Model\IndexNow;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\IndexNow\Model\IndexNow\Submitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SubmitterTest extends TestCase
{
    private const KEY = 'abcd1234-key';

    private array $logs = [];

    /** @var array<int, array> decoded payloads */
    private array $posts = [];

    private array $options = [];

    /**
     * @param array<int, array{enabled?:bool,key?:?string,base?:string}> $stores
     */
    private function build(array $stores, ?Curl $curl = null, bool $expectNoCurl = false): Submitter
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn ($path, $scope, $storeId) => $stores[$storeId]['enabled'] ?? true
        );
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn ($path, $scope, $storeId) => array_key_exists('key', $stores[$storeId] ?? [])
                ? $stores[$storeId]['key']
                : self::KEY
        );

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function ($storeId) use ($stores) {
            if (!isset($stores[$storeId])) {
                throw new \RuntimeException('Unknown store');
            }
            $store = $this->createStub(Store::class);
            $store->method('getBaseUrl')->willReturn($stores[$storeId]['base'] ?? 'https://shop.example/');
            $store->method('isFrontUrlSecure')->willReturn(true);
            return $store;
        });

        if ($expectNoCurl) {
            $curlFactory = $this->createMock(CurlFactory::class);
            $curlFactory->expects($this->never())->method('create');
        } else {
            $curlFactory = $this->createStub(CurlFactory::class);
            $curlFactory->method('create')->willReturn($curl ?? $this->curl(200));
        }

        $logger = $this->createStub(LoggerInterface::class);
        foreach (['info', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
                $this->logs[] = [$level, $message, $context];
            });
        }

        return new Submitter($curlFactory, $storeManager, $scopeConfig, $logger);
    }

    private function curl(int $status, string $body = '', ?\Throwable $failure = null): Curl
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('setOption')->willReturnCallback(function ($option, $value) {
            $this->options[$option] = $value;
        });
        $post = $curl->method('post');
        if ($failure !== null) {
            $post->willThrowException($failure);
        } else {
            $post->willReturnCallback(function ($uri, $body) {
                $this->posts[] = ['uri' => $uri] + json_decode($body, true);
            });
        }
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($body);
        return $curl;
    }

    #[DataProvider('keyProvider')]
    public function testKeyValidation(string $key, bool $valid): void
    {
        $this->assertSame($valid, Submitter::isValidKey($key));
    }

    public static function keyProvider(): array
    {
        return [
            'min length'  => ['abcd1234', true],
            'max length'  => [str_repeat('a', 128), true],
            'dashes'      => ['ab-cd-12-34', true],
            'too short'   => ['abc1234', false],
            'too long'    => [str_repeat('a', 129), false],
            'underscore'  => ['abcd_1234', false],
            'dot'         => ['abcd1234.txt', false],
            'inner space' => ['abcd 1234', false],
            'empty'       => ['', false],
        ];
    }

    public function testNothingIsSentWithoutKey(): void
    {
        $submitter = $this->build([1 => ['key' => '']], null, true);

        $this->assertFalse($submitter->submit(['https://shop.example/a.html'], 1));
        $this->assertSame('Panth IndexNow: API key is not configured; skipping submission.', $this->logs[0][1]);
    }

    public function testNothingIsSentWithInvalidKey(): void
    {
        $submitter = $this->build([1 => ['key' => 'bad key!']], null, true);

        $this->assertFalse($submitter->submit(['https://shop.example/a.html'], 1));
        $this->assertSame('Panth IndexNow: API key has an invalid format; skipping submission.', $this->logs[0][1]);
    }

    public function testDisabledStoreFailsWithoutSending(): void
    {
        $submitter = $this->build([1 => ['enabled' => false]], null, true);

        $this->assertFalse($submitter->submit(['https://shop.example/a.html'], 1));
    }

    public function testUnknownStoreIsLoggedAndFails(): void
    {
        $submitter = $this->build([], null, true);

        $this->assertFalse($submitter->submit(['https://shop.example/a.html'], 7));
        $this->assertSame(['error', 'Panth IndexNow: cannot resolve store.'], array_slice($this->logs[0], 0, 2));
    }

    public function testBaseUrlWithoutHostFails(): void
    {
        $submitter = $this->build([1 => ['base' => '/relative/']], null, true);

        $this->assertFalse($submitter->submit(['https://shop.example/a.html'], 1));
    }

    public function testEmptyUrlListIsANoOpSuccess(): void
    {
        $submitter = $this->build([1 => []], null, true);

        $this->assertTrue($submitter->submit([], 1));
        $this->assertTrue($submitter->submit(['', null], 1));
    }

    public function testRequestTimeoutAndPayloadAreApplied(): void
    {
        $submitter = $this->build([1 => []]);

        $this->assertTrue($submitter->submit(
            ['https://shop.example/a.html', 'https://other.example/b.html'],
            1,
            Submitter::REQUEST_TIMEOUT
        ));
        $this->assertSame(Submitter::REQUEST_TIMEOUT, $this->options[CURLOPT_TIMEOUT]);
        $this->assertSame(2, $this->options[CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame([
            'uri'         => 'https://api.indexnow.org/IndexNow',
            'host'        => 'shop.example',
            'key'         => self::KEY,
            'keyLocation' => 'https://shop.example/' . self::KEY . '.txt',
            'urlList'     => ['https://shop.example/a.html'],
        ], $this->posts[0]);
        $this->assertSame('info', $this->logs[0][0]);
    }

    public function testTimeoutIsClampedToTheSupportedRange(): void
    {
        $this->build([1 => []])->submit(['https://shop.example/a.html'], 1, 600);
        $this->assertSame(Submitter::TIMEOUT, $this->options[CURLOPT_TIMEOUT]);
        $this->assertSame(5, $this->options[CURLOPT_CONNECTTIMEOUT]);

        $this->build([1 => []])->submit(['https://shop.example/a.html'], 1, 0);
        $this->assertSame(1, $this->options[CURLOPT_TIMEOUT]);
        $this->assertSame(1, $this->options[CURLOPT_CONNECTTIMEOUT]);
    }

    public function testForeignUnsafeAndOutOfPathUrlsAreFilteredOut(): void
    {
        $submitter = $this->build([1 => ['base' => 'https://Shop.Example/de/']]);

        $this->assertTrue($submitter->submit([
            'https://shop.example/de/a.html',
            'http://SHOP.example/de/b.html',
            'https://shop.example/de/a.html',
            'https://shop.example/fr/c.html',
            'ftp://shop.example/de/d.html',
            'https://user:pw@shop.example/de/e.html',
            'https://evil.example/de/f.html',
            '//shop.example/de/g.html',
            'not a url',
            42,
        ], 1));
        $this->assertSame(
            ['https://shop.example/de/a.html', 'http://SHOP.example/de/b.html'],
            $this->posts[0]['urlList']
        );
        $this->assertSame('https://Shop.Example/de/' . self::KEY . '.txt', $this->posts[0]['keyLocation']);
    }

    public function testNoRequestWhenEveryUrlIsFilteredOut(): void
    {
        $submitter = $this->build([1 => []], null, true);

        $this->assertTrue($submitter->submit(['https://other.example/a.html'], 1));
    }

    public function testStoresSharingHostAndKeyAreSentInOneRequest(): void
    {
        $submitter = $this->build([1 => [], 2 => [], 3 => ['base' => 'https://b.example/']]);

        $this->assertTrue($submitter->submitByStore([
            1 => ['https://shop.example/a.html'],
            2 => ['https://shop.example/a.html', 'https://shop.example/b.html'],
            3 => ['https://b.example/c.html'],
        ]));
        $this->assertCount(2, $this->posts);
        $this->assertSame(['https://shop.example/a.html', 'https://shop.example/b.html'], $this->posts[0]['urlList']);
        $this->assertSame(['https://b.example/c.html'], $this->posts[1]['urlList']);
    }

    public function testOneFailingStoreMarksTheWholeSubmissionFailedButOthersAreSent(): void
    {
        $submitter = $this->build([1 => [], 2 => ['enabled' => false]]);

        $this->assertFalse($submitter->submitByStore([
            1 => ['https://shop.example/a.html'],
            2 => ['https://shop.example/b.html'],
        ]));
        $this->assertCount(1, $this->posts);
    }

    public function testNonSuccessStatusIsLoggedWithTruncatedBody(): void
    {
        $submitter = $this->build([1 => []], $this->curl(422, str_repeat('x', 900)));

        $this->assertFalse($submitter->submit(['https://shop.example/a.html'], 1));
        [$level, $message, $context] = $this->logs[0];
        $this->assertSame('warning', $level);
        $this->assertSame('Panth IndexNow: unexpected HTTP status.', $message);
        $this->assertSame(422, $context['status']);
        $this->assertSame(500, strlen($context['body']));
        $this->assertSame(1, $context['count']);
    }

    public function testAcceptedStatusCountsAsSuccess(): void
    {
        $this->assertTrue($this->build([1 => []], $this->curl(202))->submit(['https://shop.example/a.html'], 1));
    }

    public function testTransportErrorIsLoggedAndFails(): void
    {
        $submitter = $this->build([1 => []], $this->curl(0, '', new \RuntimeException('resolve failed')));

        $this->assertFalse($submitter->submit(['https://shop.example/a.html'], 1));
        $this->assertSame(['error', 'Panth IndexNow: request failed.'], array_slice($this->logs[0], 0, 2));
        $this->assertSame('resolve failed', $this->logs[0][2]['error']);
    }
}
