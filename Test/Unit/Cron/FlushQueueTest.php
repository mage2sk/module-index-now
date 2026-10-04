<?php
declare(strict_types=1);

namespace Panth\IndexNow\Test\Unit\Cron;

use Panth\IndexNow\Cron\FlushQueue;
use Panth\IndexNow\Model\IndexNow\Queue;
use Panth\IndexNow\Model\IndexNow\Submitter;
use PHPUnit\Framework\TestCase;

class FlushQueueTest extends TestCase
{
    public function testCronModeFlushesEverythingImmediately(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->once())->method('isCronMode')->willReturn(true);
        $queue->expects($this->once())->method('flush')->with(null, Submitter::TIMEOUT, 0)->willReturn(3);

        (new FlushQueue($queue))->execute();
    }

    public function testRequestModeOnlyPicksUpRowsLeftBehindForTwoMinutes(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->once())->method('isCronMode')->willReturn(false);
        $queue->expects($this->once())->method('flush')->with(null, Submitter::TIMEOUT, 120)->willReturn(0);

        (new FlushQueue($queue))->execute();
    }
}
