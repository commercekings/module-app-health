<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Config\Backend;

use CommerceKings\AppHealth\Model\Log\FileLogSource;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/** One log file name in var/log: a plain name ending in .log - no list, no path, no "..", not debug.log. */
class LogFile extends Value
{
    /**
     * @return $this
     * @throws LocalizedException for a list, or a name that is not a log file in var/log
     */
    public function beforeSave()
    {
        $name = trim((string)$this->getValue());
        if (preg_match('/[\s,;]/', $name)) {
            throw new LocalizedException(__('Enter one log file only (e.g. system.log); more files go in *Extra log'
                . ' files*.'));
        }
        if ($name !== '' && !preg_match(FileLogSource::NAME, $name)) {
            throw new LocalizedException(__('"%1" is not a log file name in var/log (e.g. payment.log; not'
                . ' debug.log).', $name));
        }
        $this->setValue($name);
        return parent::beforeSave();
    }
}
