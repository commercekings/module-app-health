<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Notification\Alert;
use CommerceKings\AppHealth\Model\Notification\TeamMessage;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * One run of the enabled checks, in the pool's order and within BUDGET_SECONDS: a check that would start after the
 * budget is used up is left for the next run (its last results stay). The shop-wide checks run once (scope 0), the
 * checks per website once for all websites with alerts on (scope = website id). A check that throws counts as a
 * warning ("could not run") and only its exception class is logged.
 *
 * The results go to the "checks" row by scope and code (with the time since the status last changed and the run
 * time), their worst status and counts to the hour's tally. Then each scope with news (AlertPlan) gets one queued
 * alert - the shop-wide checks' to the default scope's recipient, a website's own to the website's - and a complete
 * run pings the heartbeat. Nothing runs while alerts are off everywhere and no heartbeat is set.
 */
class Runner
{
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
        private readonly float $budgetSeconds = self::BUDGET_SECONDS
    ) {
    }

    /**
     * @return array{ran: array<string, array{status: string, ms: float}>, skipped: string[], queued: int[],
     *     complete: bool, ms: float} what the run did (queued: the scopes alerted, 0 = shop-wide)
     */
    public function run(): array
    {
        $started = microtime(true);
        $report = ['ran' => [], 'skipped' => [], 'queued' => [], 'complete' => true, 'ms' => 0.0];
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
                    'since' => $unchanged ? (int)$last['since'] : $now, 'checked_at' => $now, 'ms' => $ms];
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
        $this->state->commit();
        if ((int)gmdate('i', $now) < 5) {
            $this->store->deleteOldTallies($now); // once an hour
        }
        $this->queueAlerts($targets, $checks, $now, $report);
        if ($report['complete'] && $this->heartbeat->isConfigured()) {
            $this->heartbeat->ping();
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
            if (AlertPlan::changes($checks[$scope] ?? [], $alerted, $now, $repeat) === []) {
                continue;
            }
            try {
                if ($this->teamMessage->queue(Alert::CODE, $scope)) {
                    $report['queued'][] = $scope;
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('CommerceKings_AppHealth: alert for %s not queued (%s)',
                    $scope ? 'website ' . $scope : 'the shop', get_class($e)));
                $report['complete'] = false;
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
