<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\Stdlib\DateTime\DateTime;

/** Maintenance mode (var/.maintenance.flag) on for over "minutes" (fail), timed from when the check first saw it. */
class Maintenance implements CheckInterface
{
    public const CODE = 'maintenance';

    public function __construct(
        private readonly MaintenanceMode $maintenanceMode,
        private readonly Config $config,
        private readonly RunState $state,
        private readonly DateTime $dateTime
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Maintenance mode';
    }

    public function run(): CheckResult
    {
        if (!$this->maintenanceMode->isOn()) {
            if (!empty($this->state->get('cursor:' . self::CODE)['since'])) {
                $this->state->set('cursor:' . self::CODE, []);
            }
            return CheckResult::ok();
        }
        $now = $this->dateTime->gmtTimestamp();
        $since = (int)($this->state->get('cursor:' . self::CODE)['since'] ?? 0) ?: $now;
        $this->state->set('cursor:' . self::CODE, ['since' => $since]);
        return $since <= $now - $this->config->getInt(self::CODE, 'minutes', 15) * 60
            ? CheckResult::fail('on for ' . Format::duration($now - $since))
            : CheckResult::ok();
    }
}
