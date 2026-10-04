<?php
declare(strict_types=1);

namespace Panth\IndexNow\Test\Unit\Model\Config\Source;

use Panth\IndexNow\Model\Config\Source\SubmissionMode;
use Panth\IndexNow\Model\IndexNow\Queue;
use PHPUnit\Framework\TestCase;

class SubmissionModeTest extends TestCase
{
    public function testOffersRequestAndCronModes(): void
    {
        $options = (new SubmissionMode())->toOptionArray();

        $this->assertSame([Queue::MODE_REQUEST, Queue::MODE_CRON], array_column($options, 'value'));
        $this->assertStringContainsString('request', (string) $options[0]['label']);
        $this->assertStringContainsString('cron', (string) $options[1]['label']);
    }
}
