<?php
declare(strict_types=1);

namespace Panth\IndexNow\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Panth\IndexNow\Model\Config\Backend\ApiKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApiKeyTest extends TestCase
{
    private function backend(string $value): ApiKey
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));

        $backend = new ApiKey(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class)
        );
        $backend->setValue($value);
        return $backend;
    }

    public function testValidKeyIsTrimmedAndSaved(): void
    {
        $backend = $this->backend("  abcd1234-KEY \n");

        $this->assertSame($backend, $backend->beforeSave());
        $this->assertSame('abcd1234-KEY', $backend->getValue());
    }

    public function testEmptyValueIsAllowedSoTheKeyCanBeCleared(): void
    {
        $backend = $this->backend('   ');

        $backend->beforeSave();

        $this->assertSame('', $backend->getValue());
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidKeyIsRejected(string $value): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('8 to 128 characters');

        $this->backend($value)->beforeSave();
    }

    public static function invalidProvider(): array
    {
        return [
            'too short'  => ['abc1234'],
            'too long'   => [str_repeat('a', 129)],
            'underscore' => ['abcd_1234'],
            'space'      => ['abcd 1234'],
            'slash'      => ['abcd/12345'],
        ];
    }

    public function testBoundaryLengthsAreAccepted(): void
    {
        $short = $this->backend('abcd1234');
        $short->beforeSave();
        $long = $this->backend(str_repeat('Z', 128));
        $long->beforeSave();

        $this->assertSame('abcd1234', $short->getValue());
        $this->assertSame(128, strlen($long->getValue()));
    }
}
