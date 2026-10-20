<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\Cause;
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
    /** On this long: likely left on after a deployment. */
    public const LEFT_ON_MINUTES = 30;

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

    public function getHint(): string
    {
        return (string)__('Did a deployment stop halfway? bin/magento maintenance:status, then maintenance:disable.');
    }

    public function getLabel(): string
    {
        return 'Maintenance';
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
        if ($since > $now - $this->config->getInt(self::CODE, 'minutes', 15) * 60) {
            return CheckResult::ok();
        }
        $causes = [new Cause('maintenance_on', 'on since ' . Format::at($since) . ' (var/.maintenance.flag)')];
        if ($since <= $now - self::LEFT_ON_MINUTES * 60) {
            $causes[] = new Cause('maintenance_left_on', 'likely left on after a deployment', (string)__('If the'
                . ' deployment is done: bin/magento maintenance:disable. If it stopped halfway, finish it first.'));
        }
        return CheckResult::fail('on for ' . Format::duration($now - $since), [], 'Maintenance mode left on')
            ->withCauses($causes);
    }
}
