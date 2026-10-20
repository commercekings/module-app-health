<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\Cause;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\Log\Normalizer;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Magento cron from cron_schedule: no other job has run for "stale_minutes" (fail), a job has been running for over
 * "running_minutes" (fail), jobs ended with an error since the last run (warn, job codes and counts), MISSED or more
 * jobs missed in the last hour (warn), or this check itself did not run for GAP_MINUTES up to GAP_MAX_HOURS (warn:
 * cron was down and is back). A job that ended with an error stays a warning until it succeeds again (or
 * "quiet_minutes" pass): no news is no recovery. Counts: runs and errors.
 *
 * Causes: nothing scheduled at all (cron not started) or scheduled but not started; a stuck job and how many jobs of
 * its group wait; a job failing REPEATED times in 2 hours, with its latest error masked; too many missed jobs.
 *
 * Every query filters on the job codes Magento's cron configuration knows, a status and a scheduled_at window, so it
 * uses cron_schedule's (job_code, status, scheduled_at) index. The messages column (error text) is read only for a job
 * failing repeatedly - its latest row, first line, masked.
 */
class Cron implements CheckInterface
{
    public const CODE = 'cron';
    /** This module's own job: its runs don't prove the rest of cron works. */
    public const OWN_JOB = 'commercekings_app_health_run';
    public const GAP_MINUTES = 15;
    /** A longer gap is a module that was off (or cron down for days, which the summary and heartbeat show). */
    public const GAP_MAX_HOURS = 24;
    /** Nothing scheduled for this long: cron is not started at all. */
    public const NOT_STARTED_SECONDS = 600;
    /** Errors of one job in 2 hours that make it "failing repeatedly". */
    public const REPEATED = 3;
    /** Missed jobs in an hour that warn. */
    public const MISSED = 10;
    /** How far back a finished job is looked for after it was scheduled. */
    private const LATE_START_SECONDS = 7200;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CronConfig $cronConfig,
        private readonly Config $config,
        private readonly RunState $state,
        private readonly DateTime $dateTime,
        private readonly Normalizer $normalizer
    ) {
    }

        /** What to do, for a problem without a hint of its own. */
    private function hint(): string
    {
        return (string)__('Is the cron container (or crontab) running? bin/magento cron:run by hand shows errors.');
    }

    public function getLabel(): string
    {
        return 'Cron';
    }

    public function run(array $websiteIds): array
    {
        return [self::SHOP => $this->check()->withHint($this->hint())];
    }

    private function check(): CheckResult
    {
        $now = $this->dateTime->gmtTimestamp();
        $cursor = (array)($this->state->get('cursor:' . self::CODE) ?? []);
        $last = (int)($cursor['last'] ?? 0);
        $this->state->set('cursor:' . self::CODE, ['last' => $now] + $cursor);
        if ($last > 0 && $now - $last > self::GAP_MAX_HOURS * 3600) {
            $last = 0; // not run for a long time: start afresh
        }
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
        $causes = [];
        $stuck = false;
        foreach ($this->runningSince($jobs, $now - $runningMinutes * 60, $now) as $job => $startedAt) {
            $fail[] = sprintf('%s running for %s', $job, Format::duration($now - $startedAt));
            $stuck = true;
            $causes[] = new Cause('cron_job_stuck', sprintf('job %s running for %s (since %s)', $job,
                Format::duration($now - $startedAt), Format::at($startedAt)),
                (string)__('The process may have crashed: restart cron, then clear the stuck job (status "running" in'
                    . ' cron_schedule).'));
            $waiting = $this->waitingInGroup($job, $now);
            if ($waiting > 0) {
                $causes[] = new Cause('cron_group_waiting', sprintf('%d other %s in its group %s waiting', $waiting,
                    $waiting === 1 ? 'job' : 'jobs', $waiting === 1 ? 'is' : 'are'));
            }
        }
        $others = array_values(array_diff($jobs, [self::OWN_JOB]));
        $window = ($staleMinutes + 60) * 60;
        $lastActivity = $others === [] ? $now : $this->lastActivity($others, $now - $window);
        if ($lastActivity === null || $lastActivity < $now - $staleMinutes * 60) {
            $fail[] = $lastActivity === null ? sprintf('no job has run for over %s', Format::duration($window))
                : sprintf('no job has run since %s (%s)', Format::at($lastActivity),
                    Format::duration($now - $lastActivity));
            $causes[] = $this->scheduledSince($others, $now - self::NOT_STARTED_SECONDS) === 0
                ? new Cause('cron_not_started', ($lastActivity === null
                    ? sprintf('nothing scheduled or run for over %s', Format::duration($window))
                    : sprintf('nothing scheduled or run since %s (%s)', Format::at($lastActivity),
                        Format::duration($now - $lastActivity))) . ': cron is not started',
                    (string)__('Start the cron container, or install'
                    . ' Magento\'s crontab (bin/magento cron:install).'))
                : new Cause('cron_not_running', 'jobs are scheduled but none has started', (string)__('cron:run may'
                    . ' fail as it starts: run bin/magento cron:run by hand and read its output.'));
        }
        $failing = $this->stillFailing((array)($cursor['failing'] ?? []), $errors, $now);
        $this->state->set('cursor:' . self::CODE, ['last' => $now, 'failing' => $failing]);
        $quietFailing = array_diff_key($failing, $errors);
        if ($quietFailing !== []) {
            // no news is no recovery: still failing until the job succeeds again
            ksort($quietFailing);
            $warn[] = implode(', ', array_map(fn ($job, $at) => sprintf('%s failing since %s', $job, Format::at($at)),
                array_keys(array_slice($quietFailing, 0, 3, true)), array_slice($quietFailing, 0, 3, true)))
                . ' (no successful run since)';
        }
        if ($errors !== []) {
            $warn[] = sprintf('%s (%s)', Format::many(array_sum($errors), 'new cron errors'), Format::top($errors));
            foreach ($this->failingRepeatedly(array_keys($errors), $now) as $job => [$times, $message]) {
                $causes[] = new Cause('cron_job_failing', sprintf('job %s failed %d times in 2 h%s', $job, $times,
                    $message !== '' ? ': ' . $message : ''), (string)__('Fix the cause of the job\'s error (in'
                    . ' cron_schedule.messages and var/log), or disable the job.'));
            }
        }
        $missed = $this->missedLastHour($jobs, $now);
        if ($missed >= self::MISSED) {
            $warn[] = sprintf('%d jobs missed in the last hour', $missed);
            $causes[] = new Cause('cron_missed', sprintf('%d jobs missed in the last hour', $missed),
                (string)__('Cron runs too rarely or a run takes too long: schedule cron every minute and check how'
                    . ' long bin/magento cron:run takes.'));
        }
        if ($last > 0 && $now - $last > self::GAP_MINUTES * 60) {
            $warn[] = sprintf('this check did not run for %s (cron was down)', Format::duration($now - $last));
        }
        $detail = implode('; ', array_merge($fail, $warn));
        $result = match (true) {
            $fail !== [] => CheckResult::fail($detail, $counts, $stuck ? 'Cron jobs stuck' : 'Cron stopped'),
            $warn !== [] => CheckResult::warn($detail, $counts, match (true) {
                $errors !== [] || $quietFailing !== [] => 'Cron job errors',
                $missed >= self::MISSED => 'Cron jobs missed',
                default => 'Cron was down',
            }),
            default => CheckResult::ok('', $counts),
        };
        return $causes === [] ? $result : $result->withCauses($causes);
    }

    /**
     * The jobs that ended with an error and have not succeeded since: this run's errors, and the earlier ones without
     * a success after their last error and newer than "quiet_minutes" - one indexed query, only while there are some.
     *
     * @param array<string, int> $failing job code => last error time
     * @param array<string, int> $errors this run's errors by job code
     * @return array<string, int>
     */
    private function stillFailing(array $failing, array $errors, int $now): array
    {
        $quiet = $now - $this->config->getInt(self::CODE, 'quiet_minutes', 60) * 60;
        $earlier = array_filter(array_diff_key(array_map('intval', $failing), $errors), fn ($at) => $at > $quiet);
        if ($earlier !== []) {
            $db = $this->resource->getConnection();
            $succeeded = $db->fetchPairs($db->select()
                ->from($this->resource->getTableName('cron_schedule'), ['job_code', 'last' => 'MAX(finished_at)'])
                ->where('job_code IN (?)', array_keys($earlier))
                ->where('status = ?', 'success')
                ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', min($earlier) - self::LATE_START_SECONDS))
                ->group('job_code'));
            foreach ($succeeded as $job => $at) {
                if ((int)strtotime($at . ' UTC') > $earlier[$job]) {
                    unset($earlier[$job]);
                }
            }
        }
        return $earlier + array_fill_keys(array_keys($errors), $now);
    }

    /** Pending jobs of the stuck job's group already due (the last day), the group's codes only. */
    private function waitingInGroup(string $job, int $now): int
    {
        $group = [];
        foreach ($this->cronConfig->getJobs() as $jobs) {
            if (isset(((array)$jobs)[$job])) {
                $group = array_map('strval', array_keys((array)$jobs));
            }
        }
        $group = array_values(array_diff($group, [$job]));
        if ($group === []) {
            return 0;
        }
        $db = $this->resource->getConnection();
        return (int)$db->fetchOne($db->select()
            ->from($this->resource->getTableName('cron_schedule'), ['count' => 'COUNT(*)'])
            ->where('job_code IN (?)', $group)
            ->where('status = ?', 'pending')
            ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', $now - 86400))
            ->where('scheduled_at < ?', gmdate('Y-m-d H:i:s', $now)));
    }

    /** @param string[] $jobs rows scheduled since $since, any status (cron generates them ahead) */
    private function scheduledSince(array $jobs, int $since): int
    {
        if ($jobs === []) {
            return 0;
        }
        $db = $this->resource->getConnection();
        return (int)$db->fetchOne($db->select()
            ->from($this->resource->getTableName('cron_schedule'), ['count' => 'COUNT(*)'])
            ->where('job_code IN (?)', $jobs)
            ->where('status IN (?)', ['pending', 'running', 'success', 'missed', 'error'])
            ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', $since)));
    }

    /**
     * Jobs among $jobs with REPEATED errors or more in the last 2 hours, at most 2, with their latest error masked
     * (the messages column, read for these rows only).
     *
     * @param string[] $jobs
     * @return array<string, array{0: int, 1: string}>
     */
    private function failingRepeatedly(array $jobs, int $now): array
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('cron_schedule');
        $select = $db->select()->from($table, ['job_code', 'count' => 'COUNT(*)'])
            ->where('job_code IN (?)', $jobs)
            ->where('status = ?', 'error')
            ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', $now - 7200))
            ->group('job_code')
            ->limit(2);
        $select->having('COUNT(*) >= ?', self::REPEATED);
        $times = $db->fetchPairs($select);
        $failing = [];
        foreach ($times as $job => $count) {
            if (!$this->config->isLogMessageIncluded()) {
                $failing[(string)$job] = [(int)$count, '']; // the error text not read at all
                continue;
            }
            $message = (string)$db->fetchOne($db->select()->from($table, ['messages'])
                ->where('job_code = ?', $job)
                ->where('status = ?', 'error')
                ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', $now - 7200))
                ->order('scheduled_at DESC')
                ->limit(1));
            $firstLine = (string)strtok($message, "\n");
            $failing[(string)$job] = [(int)$count, $firstLine !== '' ? $this->normalizer->mask($firstLine) : ''];
        }
        return $failing;
    }

    /** @param string[] $jobs */
    private function missedLastHour(array $jobs, int $now): int
    {
        $db = $this->resource->getConnection();
        return (int)$db->fetchOne($db->select()
            ->from($this->resource->getTableName('cron_schedule'), ['count' => 'COUNT(*)'])
            ->where('job_code IN (?)', $jobs)
            ->where('status = ?', 'missed')
            ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', $now - 3600)));
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
