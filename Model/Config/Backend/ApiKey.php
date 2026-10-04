<?php
declare(strict_types=1);

namespace Panth\IndexNow\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Panth\IndexNow\Model\IndexNow\Submitter;

class ApiKey extends Value
{
    public function beforeSave()
    {
        $key = trim((string) $this->getValue());
        if ($key !== '' && !Submitter::isValidKey($key)) {
            throw new LocalizedException(
                __('The IndexNow API key must be 8 to 128 characters long and contain only letters, digits and dashes.')
            );
        }
        $this->setValue($key);
        return parent::beforeSave();
    }
}
