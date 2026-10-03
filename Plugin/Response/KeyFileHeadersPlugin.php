<?php
declare(strict_types=1);

namespace Panth\IndexNow\Plugin\Response;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Panth\IndexNow\Controller\Key\Index as KeyController;

class KeyFileHeadersPlugin
{
    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    public function beforeSendResponse(HttpResponse $subject): void
    {
        if (!$this->isKeyRequest()) {
            return;
        }
        $subject->setHeader('X-Robots-Tag', KeyController::ROBOTS_DIRECTIVE, true);
    }

    private function isKeyRequest(): bool
    {
        if (!$this->request instanceof HttpRequest) {
            return false;
        }
        return strtolower((string) $this->request->getModuleName()) === KeyController::ROUTE_FRONT_NAME
            && strtolower((string) $this->request->getControllerName()) === 'key';
    }
}
