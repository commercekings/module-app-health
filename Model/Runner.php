<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Notification\Alert;
use CommerceKings\AppHealth\Model\Notification\Status;
use CommerceKings\AppHealth\Model\Notification\TeamMessage;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * One run of the enabled checks, in the pool's order and within BUDGET_SECONDS: a check that would start after the
 * budget is used up is left for the next run (its last results stay). The shop-wide checks run once (scope 0), the
 * checks per website once for all websites with alerts on (scope = website id). A check that throws counts as a
 * warning ("could not run") and only its exception class is logged.
 *
 * The results go to the "checks" row by scope and code (with the time since the status last changed and the run
 * time), their worst status and counts to the hour's tally, their notices to "notices:<scope>". Then each scope with
 * news (AlertPlan: a change, or a notice not told yet) gets one queued alert - the shop-wide checks' to the default
 * scope's recipient, a website's own to the website's - and, once per *Hourly status every*, the hourly status to the
 * default scope's recipient (tried at most once per interval: one statement; no read on the other runs).
 *
 * A finished run pings the heartbeat - also when a check threw or the budget left one out ("degraded" in the report):
 * cron runs and the run got to its end - unless an alert could not be queued, or (with the hourly status on) the
 * last hourly status did not go out within its interval and STATUS_GRACE_MINUTES: then alerts can't reach the team,
 * and the outside service is the one to tell them. Nothing runs while alerts are off everywhere and no heartbeat is
 * set.
 */
class Runner
{
    /** How late the hourly status may be before the heartbeat stops. */
    public const STATUS_GRACE_MINUTES = 10;
    public const BUDGET_SECONDS = 5.0;

    public function __construct(
        private readonly CheckPool $pool,
        private readonly Config $config,
        private readonly RunState $state,
        private readonly StateStore $store,
        private readonly Targets $targets,
        private readonly TeamMessage $teamMessage,
        private readonly Heartbeat $heartbeat,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly DeliveryStateInterface $deliveryState,
        private readonly float $budgetSeconds = self::BUDGET_SECONDS
    ) {
    }

    /**
     * @return array{ran: array<string, array{status: string, ms: float}>, skipped: string[], queued: int[],
     *     complete: bool, degraded: bool, ms: float} what the run did (queued: the scopes alerted, 0 = shop-wide;
     *     complete: every check ran; degraded: one threw or was left out)
     */
    public function run(): array
    {
        $started = microtime(true);
        $report = ['ran' => [], 'skipped' => [], 'queued' => [], 'complete' => true, 'degraded' => false,
            'ms' => 0.0];
        $targets = $this->targets->websites();
        $shopWide = $this->targets->shopWide();
        if ($shopWide !== null) {
            $targets = [0 => $shopWide] + $targets;
        }
        if ($targets === [] && !$this->heartbeat->isConfigured()) {
            return $report;
        }
        $now = $this->dateTime->gmtTimestamp();
        $this->state->begin();
        $previous = $this->state->get('checks') ?? [];
        $hour = 'tally:' . gmdate('YmdH', $now);
        $tally = $this->state->get($hour) ?? [];
        $checks = [];
        $notices = [];
        $websiteIds = array_values(array_filter(array_keys($targets)));
        foreach ($this->pool->getAll() as $code => $check) {
            $perWebsite = !$check instanceof CheckInterface;
            if (!$this->config->isCheckEnabled($code) || ($perWebsite && $websiteIds === [])) {
                continue;
            }
            $scopes = $perWebsite ? $websiteIds : [0];
            if (microtime(true) - $started >= $this->budgetSeconds) {
                $report['skipped'][] = $code;
                $report['complete'] = false;
                foreach ($scopes as $scope) {
                    if (isset($previous[$scope][$code])) {
                        $checks[$scope][$code] = $previous[$scope][$code];
                    }
                }
                continue;
            }
            $begin = microtime(true);
            try {
                $results = $perWebsite ? $check->runForWebsites($websiteIds) : [0 => $check->run()];
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('CommerceKings_AppHealth: check %s failed (%s at %s:%d)', $code,
                    get_class($e), $e->getFile(), $e->getLine()));
                $results = [];
                $report['complete'] = false;
            }
            $ms = round((microtime(true) - $begin) * 1000, 1);
            $worst = 0;
            foreach ($scopes as $scope) {
                $result = $results[$scope] ?? null;
                if (!$result instanceof CheckResult) {
                    $result = CheckResult::warn('the check could not run (see the Magento log)');
                }
                $worst = max($worst, CheckResult::rank($result->status));
                $last = $previous[$scope][$code] ?? null;
                $unchanged = $last !== null && ($last['status'] ?? null) === $result->status;
                $checks[$scope][$code] = ['status' => $result->status, 'detail' => $result->detail,
                    'title' => $result->title, 'gauges' => $result->gauges,
                    'causes' => array_map(fn ($cause) => $cause->toArray(), $result->causes),
                    'since' => $unchanged ? (int)$last['since'] : $now,
                    'runs' => $unchanged ? (int)($last['runs'] ?? 1) + 1 : 1, 'checked_at' => $now, 'ms' => $ms];
                if ($result->notices !== []) {
                    $notices[$scope][$code] = $result->notices;
                }
                $tally[$scope][$code] = [
                    'w' => max((int)($tally[$scope][$code]['w'] ?? 0), CheckResult::rank($result->status)),
                    'c' => $this->add((array)($tally[$scope][$code]['c'] ?? []), $result->counts),
                ];
            }
            $report['ran'][$code] = ['status' => CheckResult::fromRank($worst), 'ms' => $ms];
        }
        if ($report['skipped'] !== []) {
            $this->logger->warning(sprintf('CommerceKings_AppHealth: time budget used up; skipped %s',
                implode(', ', $report['skipped'])));
        }
        $this->state->set('checks', $checks);
        $this->state->set($hour, $tally);
        $statusMinutes = $this->config->getHourlyStatusMinutes();
        $status = $shopWide !== null && $this->config->isHourlyStatusEnabled();
        $statusDue = $status && (int)($this->state->get('status')['tried'] ?? 0) <= $now - ($statusMinutes - 1) * 60;
        if ($statusDue) {
            $this->state->set('status', ['tried' => $now]);
        }
        foreach ($notices as $scope => $byCode) {
            $this->state->set(AlertPlan::NOTICES . $scope,
                AlertPlan::addNotices($this->state->get(AlertPlan::NOTICES . $scope) ?? [], $byCode, $now));
        }
        $this->state->commit();
        if ((int)gmdate('i', $now) < 5) {
            $this->store->deleteOldTallies($now); // once an hour
        }
        $this->queueAlerts($targets, $checks, $now, $report);
        if ($statusDue) {
            try {
                // not when one went out within the interval (e.g. a run's clock a little early)
                $report['status'] = $this->teamMessage->queue(Status::CODE, 0, sprintf('(sent_at IS NULL OR sent_at'
                    . ' < NOW() - INTERVAL %d MINUTE)', $statusMinutes - 2));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('CommerceKings_AppHealth: hourly status not queued (%s)', get_class($e)));
                $report['queue_failed'] = true;
            }
        }
        $report['degraded'] = !$report['complete'];
        if ($this->heartbeat->isConfigured()) {
            $report['heartbeat'] = match (true) {
                !empty($report['queue_failed']) => 'held: an alert could not be queued',
                $status && !$this->statusWentOut($now, $statusMinutes) => 'held: no hourly status went out recently',
                default => $this->heartbeat->ping() ? 'pinged' : 'ping failed',
            };
        }
        $report['ms'] = round((microtime(true) - $started) * 1000, 1);
        return $report;
    }

    /** Whether the last hourly status went out within its interval and the grace time (one read). */
    private function statusWentOut(int $now, int $statusMinutes): bool
    {
        $sentAt = (string)($this->deliveryState->load(Status::CODE, 0)['sent_at'] ?? '');
        return $sentAt !== ''
            && (int)strtotime($sentAt . ' UTC') >= $now - ($statusMinutes + self::STATUS_GRACE_MINUTES) * 60;
    }

    /**
     * @param array<int, Target> $targets by scope
     * @param array<int, array<string, array<mixed>>> $checks by scope
     * @param array<string, mixed> $report
     */
    private function queueAlerts(array $targets, array $checks, int $now, array &$report): void
    {
        $repeat = $this->config->getRepeatHours() * 3600;
        foreach (array_keys($targets) as $scope) {
            $alerted = $this->state->get('alerted:' . $scope) ?? [];
            $pending = AlertPlan::pendingNotices($this->state->get(AlertPlan::NOTICES . $scope) ?? [],
                $this->state->get(AlertPlan::NOTICED . $scope) ?? [], $now, $this->config->getNoticeMinutes() * 60);
            if (AlertPlan::changes($checks[$scope] ?? [], $alerted, $now, $repeat, $this->config->getRecoverRuns())
                === [] && $pending === []) {
                continue;
            }
            try {
                if ($this->teamMessage->queue(Alert::CODE, $scope)) {
                    $report['queued'][] = $scope;
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('CommerceKings_AppHealth: alert for %s not queued (%s)',
                    $scope ? 'website ' . $scope : 'the shop', get_class($e)));
                $report['queue_failed'] = true; // alerts can't go out: no heartbeat
            }
        }
    }

    /**
     * @param array<string, int> $sum
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function add(array $sum, array $counts): array
    {
        foreach ($counts as $key => $count) {
            $sum[$key] = (int)($sum[$key] ?? 0) + $count;
        }
        return $sum;
    }
}
