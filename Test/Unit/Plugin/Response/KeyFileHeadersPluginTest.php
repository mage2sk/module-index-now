<?php
declare(strict_types=1);

namespace Panth\IndexNow\Test\Unit\Plugin\Response;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Panth\IndexNow\Plugin\Response\KeyFileHeadersPlugin;
use PHPUnit\Framework\TestCase;

class KeyFileHeadersPluginTest extends TestCase
{
    private function request(string $module, string $controller): HttpRequest
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getModuleName')->willReturn($module);
        $request->method('getControllerName')->willReturn($controller);
        return $request;
    }

    public function testKeyResponseGetsOwnDirective(): void
    {
        $response = $this->createMock(HttpResponse::class);
        $response->expects($this->once())->method('setHeader')->with('X-Robots-Tag', 'noindex, nofollow', true);
        (new KeyFileHeadersPlugin($this->request('panth_indexnow', 'key')))->beforeSendResponse($response);
    }

    public function testRouteMatchIsCaseInsensitive(): void
    {
        $response = $this->createMock(HttpResponse::class);
        $response->expects($this->once())->method('setHeader')->with('X-Robots-Tag', 'noindex, nofollow', true);
        (new KeyFileHeadersPlugin($this->request('Panth_IndexNow', 'KEY')))->beforeSendResponse($response);
    }

    public function testOtherResponsesAreUntouched(): void
    {
        $response = $this->createMock(HttpResponse::class);
        $response->expects($this->never())->method('setHeader');
        (new KeyFileHeadersPlugin($this->request('catalog', 'product')))->beforeSendResponse($response);
    }

    public function testOtherControllerOfTheSameRouteIsUntouched(): void
    {
        $response = $this->createMock(HttpResponse::class);
        $response->expects($this->never())->method('setHeader');
        (new KeyFileHeadersPlugin($this->request('panth_indexnow', 'index')))->beforeSendResponse($response);
    }

    public function testNonHttpRequestIsUntouched(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getModuleName')->willReturn('panth_indexnow');
        $response = $this->createMock(HttpResponse::class);
        $response->expects($this->never())->method('setHeader');
        (new KeyFileHeadersPlugin($request))->beforeSendResponse($response);
    }
}
