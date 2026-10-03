<?php
declare(strict_types=1);

namespace Panth\IndexNow\Test\Unit\Controller;

use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\IndexNow\Controller\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private const KEY = 'AbCd1234-key';

    private array $set = [];

    private function request(string $pathInfo, string $module = ''): HttpRequest
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getModuleName')->willReturn($module);
        $request->method('getPathInfo')->willReturn($pathInfo);
        foreach (['setModuleName', 'setControllerName', 'setActionName'] as $method) {
            $request->method($method)->willReturnCallback(function ($value) use ($request, $method) {
                $this->set[$method] = $value;
                return $request;
            });
        }
        $request->method('setParam')->willReturnCallback(function ($name, $value) use ($request) {
            $this->set['param:' . $name] = $value;
            return $request;
        });
        return $request;
    }

    private function router(
        ?string $apiKey = self::KEY,
        bool $enabled = true,
        bool $storeFails = false,
        ?ActionInterface $action = null
    ): Router {
        $action = $action ?? $this->createStub(ActionInterface::class);
        $actionFactory = $this->createStub(ActionFactory::class);
        $actionFactory->method('create')->willReturnCallback(function ($class) use ($action) {
            $this->set['action'] = $class;
            return $action;
        });

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn($enabled);
        $scopeConfig->method('getValue')->willReturn($apiKey);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        if ($storeFails) {
            $storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));
        } else {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn(1);
            $storeManager->method('getStore')->willReturn($store);
        }

        return new Router($actionFactory, $scopeConfig, $storeManager);
    }

    public function testMatchingKeyFileIsForwardedToTheKeyController(): void
    {
        $action = $this->createStub(ActionInterface::class);

        $result = $this->router(self::KEY, true, false, $action)->match($this->request('/abcd1234-KEY.txt'));

        $this->assertSame($action, $result);
        $this->assertSame(Forward::class, $this->set['action']);
        $this->assertSame('panth_indexnow', $this->set['setModuleName']);
        $this->assertSame('key', $this->set['setControllerName']);
        $this->assertSame('index', $this->set['setActionName']);
        $this->assertSame('abcd1234-KEY', $this->set['param:key']);
    }

    public function testAlreadyForwardedRequestIsIgnored(): void
    {
        $this->assertNull($this->router()->match($this->request('/' . self::KEY . '.txt', 'panth_indexnow')));
        $this->assertSame([], $this->set);
    }

    #[DataProvider('nonKeyPathProvider')]
    public function testPathsThatAreNotKeyFilesAreIgnored(string $path): void
    {
        $this->assertNull($this->router()->match($this->request($path)));
        $this->assertArrayNotHasKey('action', $this->set);
    }

    public static function nonKeyPathProvider(): array
    {
        return [
            'robots'     => ['/robots.txt'],
            'nested'     => ['/media/' . self::KEY . '.txt'],
            'no suffix'  => ['/' . self::KEY],
            'html'       => ['/' . self::KEY . '.html'],
            'underscore' => ['/abcd_1234_key.txt'],
            'empty'      => [''],
        ];
    }

    public function testDifferentKeyIsIgnored(): void
    {
        $this->assertNull($this->router()->match($this->request('/zzzz9999-other.txt')));
        $this->assertArrayNotHasKey('action', $this->set);
    }

    public function testDisabledModuleIsIgnored(): void
    {
        $this->assertNull($this->router(self::KEY, false)->match($this->request('/' . self::KEY . '.txt')));
        $this->assertArrayNotHasKey('action', $this->set);
    }

    public function testInvalidOrMissingConfiguredKeyIsIgnored(): void
    {
        $this->assertNull($this->router('short', true)->match($this->request('/shortkey.txt')));
        $this->assertNull($this->router(null, true)->match($this->request('/' . self::KEY . '.txt')));
        $this->assertArrayNotHasKey('action', $this->set);
    }

    public function testStoreResolutionFailureIsIgnored(): void
    {
        $this->assertNull($this->router(self::KEY, true, true)->match($this->request('/' . self::KEY . '.txt')));
        $this->assertArrayNotHasKey('action', $this->set);
    }
}
