<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;

/**
 * *Errors at once from*: log files App health reads - system.log, exception.log or one of the *Extra log files* (as
 * saved with it, else as configured); a file it does not read would never send anything.
 */
class ImmediateLogFiles extends LogFiles
{
    private const EXTRA = 'commercekings_app_health/system_log/extra_files';

    /**
     * @param string[] $names
     * @throws LocalizedException naming a file App health does not read
     */
    protected function check(array $names): void
    {
        $posted = $this->getData('fieldset_data');
        $extra = self::names((string)(is_array($posted) && array_key_exists('extra_files', $posted)
            ? $posted['extra_files'] : $this->_config->getValue(self::EXTRA)));
        foreach ($names as $name) {
            if (!in_array($name, array_merge(self::ALWAYS, $extra), true)) {
                throw new LocalizedException(__('Errors at once from: App health does not read %1; add it to Extra log'
                    . ' files first (or use system.log or exception.log).', $name));
            }
        }
    }
}
