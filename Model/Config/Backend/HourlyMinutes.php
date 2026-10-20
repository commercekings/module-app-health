<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * *Hourly status every*: whole minutes from MIN to MAX - the range the runtime keeps it in anyway (Notification\Status),
 * refused when saved instead of changed silently.
 */
class HourlyMinutes extends Value
{
    public const MIN = 15;
    public const MAX = 180;

    /** @throws LocalizedException outside MIN to MAX, or not a whole number */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if (!ctype_digit($value) || (int)$value < self::MIN || (int)$value > self::MAX) {
            throw new LocalizedException(__('Hourly status every: whole minutes from %1 to %2.', self::MIN, self::MAX));
        }
        $this->setValue($value);
        return parent::beforeSave();
    }
}
