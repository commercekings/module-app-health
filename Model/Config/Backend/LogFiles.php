<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Config\Backend;

use CommerceKings\AppHealth\Model\Log\FileLogSource;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Log file names in var/log (one, or a list separated by commas or new lines): plain names ending in .log - no path,
 * no "..", not debug.log. Saved one per line.
 */
class LogFiles extends Value
{
    /**
     * @return $this
     * @throws LocalizedException for a name that is not a log file in var/log
     */
    public function beforeSave()
    {
        $names = preg_split('/[\s,]+/', (string)$this->getValue(), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($names as $name) {
            if (!preg_match(FileLogSource::NAME, $name)) {
                throw new LocalizedException(__('"%1" is not a log file name in var/log (e.g. payment.log; not'
                    . ' debug.log).', $name));
            }
        }
        $this->setValue(implode("\n", array_unique($names)));
        return parent::beforeSave();
    }
}
