<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Api\StatusPolicyInterface;
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
 * news (AlertPlan: a change, or a notice not told yet) gets one queued alert - the shared checks' to the global
 * recipient, a website's own to its *Website channels* recipient (else the global one) - and, once per *Hourly status
 * every*, the hourly status to the global recipient. Each run reads the status's delivery state (one query); while it
 * is due, every run queues it (queue() skips one queued or waiting to retry).
 *
 * A copy of the shop (SiteDomain: its domain is not the one App health was set up on) runs nothing: no message and
 * no heartbeat ping - a staging copy must not alert the team, nor ping production's heartbeat.
 *
 * A finished run pings the heartbeat - also when a check threw or the budget left one out ("degraded" in the report):
 * cron runs and the run got to its end - unless an alert could not be queued, a message is failing to go out (its
 * sends fail or wait to be retried: TeamMessage::isFailing()), App health is on with no channel or recipient
 * (nowhere to send), or (with the hourly status on) the
 * hourly status has been due for STATUS_GRACE_MINUTES (the "status" row) and is still not out: alerts can't reach
 * the team, and the outside service is the one to tell them. Nothing runs while app health is off and no heartbeat
 * is set.
 *
 * A snapshot (run(snapshot: true), "Send status now") runs the same checks the same way and returns their results,
 * then stops: nothing is stored - the checks' own cursors included, so the next run still sees what this one saw -
 * nothing is queued or pinged, and the alerts, "Resolved" and the hourly status keep their timing.
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
        private readonly StatusPolicyInterface $statusPolicy,
        private readonly float $budgetSeconds = self::BUDGET_SECONDS,
        private readonly ?SiteDomain $siteDomain = null
    ) {
    }

    /**
     * @param bool $snapshot only the results: nothing stored, queued or pinged
     * @return array{ran: array<string, array{status: string, ms: float}>, skipped: string[], queued: int[],
     *     complete: bool, degraded: bool, ms: float, checks?: array<int, array<string, array<mixed>>>,
     *     notices?: array<int, array<string, string[]>>} what the run did (queued: the scopes alerted, 0 = shop-wide;
     *     complete: every check ran; degraded: one threw or was left out); a snapshot's checks and notices by scope
     */
    public function run(bool $snapshot = false): array
    {
        $started = microtime(true);
        $report = ['ran' => [], 'skipped' => [], 'queued' => [], 'complete' => true, 'degraded' => false,
            'ms' => 0.0];
        if (!$snapshot && $this->siteDomain !== null && !$this->siteDomain->matches()) {
            // a copy of the shop (staging, a developer's database): no message, no heartbeat ping - nothing runs
            $report['silent'] = 'copy of ' . $this->siteDomain->stored();
            return $report;
        }
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
            if (!$this->config->isCheckEnabled($code)) {
                continue;
            }
            // the scopes the check had last time
            $known = array_keys(array_filter($previous, fn ($row) => is_array($row) && isset($row[$code])));
            if (microtime(true) - $started >= $this->budgetSeconds) {
                $report['skipped'][] = $code;
                $report['complete'] = false;
                foreach ($known as $scope) {
                    $checks[$scope][$code] = $previous[$scope][$code];
                }
                continue;
            }
            $begin = microtime(true);
            try {
                $results = $check->run($websiteIds);
                // the whole shop, or each website asked about (one left out counts as "could not run")
                $scopes = isset($results[CheckInterface::SHOP]) ? [CheckInterface::SHOP] : $websiteIds;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('CommerceKings_AppHealth: check %s failed (%s at %s:%d)', $code,
                    get_class($e), $e->getFile(), $e->getLine()));
                $results = [];
                $scopes = $known ?: [CheckInterface::SHOP];
                $report['complete'] = false;
            }
            if ($scopes === []) {
                continue; // a check per website, and no website asked about
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
                    'runs' => $unchanged ? (int)($last['runs'] ?? 1) + 1 : 1, 'checked_at' => $now, 'ms' => $ms]
                    + ($result->idle ? ['idle' => true] : []);
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
        if ($snapshot) {
            // the checks' changes stay in this RunState: never committed
            $report['degraded'] = !$report['complete'];
            $report['ms'] = round((microtime(true) - $started) * 1000, 1);
            return $report + ['checks' => $checks, 'notices' => $notices];
        }
        if ($report['skipped'] !== []) {
            $this->logger->warning(sprintf('CommerceKings_AppHealth: time budget used up; skipped %s',
                implode(', ', $report['skipped'])));
        }
        $this->state->set('checks', $checks);
        $this->state->set($hour, $tally);
        // the hourly status: to the global recipient
        $statusScope = $this->config->isHourlyStatusEnabled() && $shopWide !== null ? 0 : null;
        $statusMinutes = Status::intervalMinutes($this->statusPolicy, (int)$statusScope);
        $statusDue = false;
        $statusLate = false;
        if ($statusScope !== null) {
            $sentAt = (string)($this->deliveryState->load(Status::CODE, $statusScope)['sent_at'] ?? '');
            $statusDue = $sentAt === '' || (int)strtotime($sentAt . ' UTC') <= $now - ($statusMinutes - 2) * 60;
            $dueSince = (int)($this->state->get('status')['due_since'] ?? 0);
            if ($statusDue && $dueSince === 0) {
                $this->state->set('status', ['due_since' => $dueSince = $now]);
            } elseif (!$statusDue && $dueSince > 0) {
                $this->state->set('status', []);
            }
            // late: due since the grace time and still not out (each run tried it)
            $statusLate = $statusDue && $dueSince <= $now - self::STATUS_GRACE_MINUTES * 60;
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
                $report['status'] = $this->teamMessage->queue(Status::CODE, (int)$statusScope, sprintf('(sent_at IS'
                    . ' NULL OR sent_at < NOW() - INTERVAL %d MINUTE)', $statusMinutes - 2));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('CommerceKings_AppHealth: hourly status not queued (%s)', get_class($e)));
                $report['queue_failed'] = true;
            }
        }
        $report['degraded'] = !$report['complete'];
        if ($this->heartbeat->isConfigured()) {
            $report['heartbeat'] = match (true) {
                !empty($report['queue_failed']) => 'held: an alert could not be queued',
                // the quiet hourly status alone would look fine while alerts fail into retry
                $this->teamMessage->isFailing()
                    => 'held: messages are failing to go out (the channel or the recipient)',
                // on, but no global channel or recipient: an alert could not reach anyone
                $shopWide === null && $this->config->isEnabled()
                    => 'held: App health is on but has nowhere to send (no channel or recipient)',
                $statusLate => 'held: no hourly status went out recently',
                default => $this->heartbeat->ping() ? 'pinged' : 'ping failed',
            };
        }
        $report['ms'] = round((microtime(true) - $started) * 1000, 1);
        return $report;
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
            $withShared = $this->config->isSharedToWebsites() && $targets[$scope]->ownChannel;
            if (AlertPlan::changes(AlertPlan::scopeChecks($checks, $scope, $withShared), $alerted, $now, $repeat,
                $this->config->getRecoverRuns()) === [] && $pending === []) {
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
