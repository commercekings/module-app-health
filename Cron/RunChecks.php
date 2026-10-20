<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Cron;

use CommerceKings\AppHealth\Model\Runner;

/** Cron "commercekings_app_health_run", every 5 minutes: the checks, alerts on a change and the heartbeat. */
class RunChecks
{
    public function __construct(
        private readonly Runner $runner
    ) {
    }

    public function execute(): void
    {
        $this->runner->run();
    }
}
