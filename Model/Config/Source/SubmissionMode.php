<?php
declare(strict_types=1);

namespace Panth\IndexNow\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\IndexNow\Model\IndexNow\Queue;

class SubmissionMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Queue::MODE_REQUEST, 'label' => __('At the end of the request (short timeout)')],
            ['value' => Queue::MODE_CRON, 'label' => __('By cron (every minute)')],
        ];
    }
}
