<?php
declare(strict_types=1);

namespace Panth\IndexNow\Cron;

use Panth\IndexNow\Model\IndexNow\Queue;
use Panth\IndexNow\Model\IndexNow\Submitter;

class FlushQueue
{
    private const REQUEST_MODE_MIN_AGE = 120;

    public function __construct(
        private readonly Queue $queue
    ) {
    }

    public function execute(): void
    {
        $minAge = $this->queue->isCronMode() ? 0 : self::REQUEST_MODE_MIN_AGE;
        $this->queue->flush(null, Submitter::TIMEOUT, $minAge);
    }
}
