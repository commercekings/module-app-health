<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Magento cron from cron_schedule: no other job has run for "stale_minutes" (fail), a job has been running for over
 * "running_minutes" (fail), jobs ended with an error since the last run (warn, job codes and counts), or this check
 * itself did not run for GAP_MINUTES (warn: cron was down and is back). Counts: runs and errors.
 *
 * Every query filters on the job codes Magento's cron configuration knows, a status and a scheduled_at window, so it
 * uses cron_schedule's (job_code, status, scheduled_at) index. The messages column (error text) is never read.
 */
class Cron implements CheckInterface
{
    public const CODE = 'cron';
    /** This module's own job: its runs don't prove the rest of cron works. */
    public const OWN_JOB = 'commercekings_app_health_run';
    public const GAP_MINUTES = 15;
    /** How far back a finished job is looked for after it was scheduled. */
    private const LATE_START_SECONDS = 7200;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CronConfig $cronConfig,
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
        return 'Cron';
    }

    public function run(): CheckResult
    {
        $now = $this->dateTime->gmtTimestamp();
        $last = (int)($this->state->get('cursor:' . self::CODE)['last'] ?? 0);
        $this->state->set('cursor:' . self::CODE, ['last' => $now]);
        $since = $last > 0 ? $last : $now - 300;
        $jobs = $this->jobCodes();
        if ($jobs === []) {
            return CheckResult::ok();
        }
        $staleMinutes = $this->config->getInt(self::CODE, 'stale_minutes', 5);
        $runningMinutes = $this->config->getInt(self::CODE, 'running_minutes', 60);

        [$runs, $errors] = $this->finished($jobs, $since, $now);
        $counts = ['runs' => $runs, 'errors' => array_sum($errors)];
        foreach ($errors as $job => $count) {
            $counts['errors:' . $job] = $count;
        }
        $fail = [];
        $warn = [];
        foreach ($this->runningSince($jobs, $now - $runningMinutes * 60, $now) as $job => $startedAt) {
            $fail[] = sprintf('%s running for %s', $job, Format::duration($now - $startedAt));
        }
        $others = array_values(array_diff($jobs, [self::OWN_JOB]));
        $window = ($staleMinutes + 60) * 60;
        $lastActivity = $others === [] ? $now : $this->lastActivity($others, $now - $window);
        if ($lastActivity === null) {
            $fail[] = sprintf('no job has run for over %s', Format::duration($window));
        } elseif ($lastActivity < $now - $staleMinutes * 60) {
            $fail[] = sprintf('no job has run for %s', Format::duration($now - $lastActivity));
        }
        if ($errors !== []) {
            $warn[] = sprintf('%d new cron %s (%s)', array_sum($errors), array_sum($errors) === 1 ? 'error' : 'errors',
                Format::top($errors));
        }
        if ($last > 0 && $now - $last > self::GAP_MINUTES * 60) {
            $warn[] = sprintf('this check did not run for %s (cron was down)', Format::duration($now - $last));
        }
        $detail = implode('; ', array_merge($fail, $warn));
        return match (true) {
            $fail !== [] => CheckResult::fail($detail, $counts),
            $warn !== [] => CheckResult::warn($detail, $counts),
            default => CheckResult::ok('', $counts),
        };
    }

    /** @return string[] */
    private function jobCodes(): array
    {
        $codes = [];
        foreach ($this->cronConfig->getJobs() as $jobs) {
            foreach (array_keys((array)$jobs) as $code) {
                $codes[] = (string)$code;
            }
        }
        return array_values(array_unique($codes));
    }

    /**
     * Jobs that ended since the last run: successes by finished_at, errors by executed_at (an error has no
     * finished_at).
     *
     * @param string[] $jobs
     * @return array{0: int, 1: array<string, int>} runs, errors by job code
     */
    private function finished(array $jobs, int $since, int $now): array
    {
        $db = $this->resource->getConnection();
        [$from, $to] = [gmdate('Y-m-d H:i:s', $since), gmdate('Y-m-d H:i:s', $now)];
        $rows = $db->fetchAll($db->select()
            ->from($this->resource->getTableName('cron_schedule'), ['job_code', 'status', 'count' => 'COUNT(*)'])
            ->where('job_code IN (?)', $jobs)
            ->where('status IN (?)', ['success', 'error'])
            ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', $since - self::LATE_START_SECONDS))
            ->where($db->quoteInto("(status = 'success' AND finished_at > ?", $from)
                . $db->quoteInto(' AND finished_at <= ?)', $to)
                . $db->quoteInto(" OR (status = 'error' AND COALESCE(executed_at, scheduled_at) > ?", $from)
                . $db->quoteInto(' AND COALESCE(executed_at, scheduled_at) <= ?)', $to))
            ->group(['job_code', 'status']));
        $runs = 0;
        $errors = [];
        foreach ($rows as $row) {
            if ($row['status'] === 'success') {
                $runs += (int)$row['count'];
            } else {
                $errors[(string)$row['job_code']] = (int)$row['count'];
            }
        }
        return [$runs, $errors];
    }

    /**
     * @param string[] $jobs
     * @return array<string, int> job code => started at, for jobs running since before $before (at most 10)
     */
    private function runningSince(array $jobs, int $before, int $now): array
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchPairs($db->select()
            ->from($this->resource->getTableName('cron_schedule'), ['job_code', 'started' => 'MIN(executed_at)'])
            ->where('job_code IN (?)', $jobs)
            ->where('status = ?', 'running')
            ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', $now - 86400))
            ->where('executed_at < ?', gmdate('Y-m-d H:i:s', $before))
            ->group('job_code')
            ->limit(10));
        return array_map(fn ($started) => (int)strtotime($started . ' UTC'), $rows);
    }

    /** @param string[] $jobs */
    private function lastActivity(array $jobs, int $since): ?int
    {
        $db = $this->resource->getConnection();
        $last = $db->fetchOne($db->select()
            ->from($this->resource->getTableName('cron_schedule'), ['last' => 'MAX(executed_at)'])
            ->where('job_code IN (?)', $jobs)
            ->where('status IN (?)', ['running', 'success', 'error'])
            ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', $since)));
        return $last ? (int)strtotime($last . ' UTC') : null;
    }
}
