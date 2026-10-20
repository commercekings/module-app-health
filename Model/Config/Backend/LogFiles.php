<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Config\Backend;

use CommerceKings\AppHealth\Model\Log\FileLogSource;
use CommerceKings\AppHealth\Model\Log\LogSourcePool;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * *Extra log files*: names in var/log (one, or a list separated by commas or new lines): plain names ending in .log -
 * no path, no "..", not debug.log - at most MAX, not system.log or exception.log (always read), no two that are one
 * source (LogSourcePool::fileCode(): "Payment.log" and "payment.log"). Saved one per line.
 */
class LogFiles extends Value
{
    public const MAX = 5;
    /** Read anyway, by the system log and exceptions checks. */
    public const ALWAYS = ['system.log', 'exception.log'];

    /**
     * @return $this
     * @throws LocalizedException for a name that is not a log file in var/log, or a list App health cannot read
     */
    public function beforeSave()
    {
        $names = self::names((string)$this->getValue());
        $this->check($names);
        $this->setValue(implode("\n", $names));
        return parent::beforeSave();
    }

    /**
     * @param string[] $names
     * @throws LocalizedException
     */
    protected function check(array $names): void
    {
        if (count($names) > self::MAX) {
            throw new LocalizedException(__('Extra log files: at most %1 files (%2 given).', self::MAX, count($names)));
        }
        $codes = [];
        foreach ($names as $name) {
            if (in_array($name, self::ALWAYS, true)) {
                throw new LocalizedException(__('Extra log files: %1 is always read; list other files only.', $name));
            }
            $code = LogSourcePool::fileCode($name);
            if (isset($codes[$code])) {
                throw new LocalizedException(__('Extra log files: "%1" and "%2" would be one source (%3); keep one.',
                    $codes[$code], $name, $code));
            }
            $codes[$code] = $name;
        }
    }

    /**
     * The names, each once.
     *
     * @return string[]
     * @throws LocalizedException for a name that is not a log file in var/log
     */
    protected static function names(string $value): array
    {
        $names = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($names as $name) {
            if (!preg_match(FileLogSource::NAME, $name)) {
                throw new LocalizedException(__('"%1" is not a log file name in var/log (e.g. payment.log; not'
                    . ' debug.log).', $name));
            }
        }
        return array_values(array_unique($names));
    }
}
