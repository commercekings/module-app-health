<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\Cron;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Db;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use Magento\Cron\Model\ConfigInterface;
use PHPUnit\Framework\TestCase;

class CronTest extends TestCase
{
    use Db;
    use Settings;
    use \CommerceKings\AppHealth\Test\Unit\Stub\Sensitive;

    private const NOW = 1792483200;
    private const JOBS = ['catalog_index_refresh_price', 'sitemap_generate', Cron::OWN_JOB];

    private RunState $state;

    /**
     * The database answers in the order the check asks: finished jobs (fetchAll), running ones and the repeated
     * failures (fetchPairs), then the counts and times (fetchOne: [waiting in a stuck job's group], last activity,
     * [scheduled recently when stale], [a failing job's latest error], missed in the last hour).
     *
     * @param array<int, array<string, mixed>> $finished rows of job_code, status, count
     */
    private function check(array $finished, array $pairs, array $one, ?int $lastRun = null,
        array $jobs = self::JOBS, array $failing = [], array $settings = []): CheckResult
    {
        $store = (new MemoryStore())->seed($lastRun ? ['cursor:cron' => ['last' => $lastRun, 'failing' => $failing]]
            : []);
        $this->state = new RunState($store);
        $this->state->begin();
        $cronConfig = $this->createStub(ConfigInterface::class);
        // indexer jobs in their own group, with indexer_update_all_views next to them
        $index = array_values(array_filter($jobs, fn ($job) => str_starts_with($job, 'indexer_')));
        $groups = ['default' => array_fill_keys(array_diff($jobs, $index), [])];
        if ($index !== []) {
            $groups['index'] = array_fill_keys(array_merge($index, ['indexer_update_all_views']), []);
        }
        $cronConfig->method('getJobs')->willReturn($groups);
        $db = $this->db(['fetchAll' => [$finished], 'fetchPairs' => $pairs, 'fetchOne' => $one]);
        return (new Cron($this->resource($db), $cronConfig, $this->config($settings + ['cron/stale_minutes' => '5',
            'cron/running_minutes' => '60', 'cron/quiet_minutes' => '60']), $this->state, new Clock(self::NOW),
            $this->normalizer()))->run([])[0];
    }

    private function ago(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', self::NOW - $seconds);
    }

    /** @return string[] the causes' codes */
    private function causes(CheckResult $result): array
    {
        return array_map(fn ($cause) => $cause->code, $result->causes);
    }

    public function testAHealthyCronIsOkWithItsRuns(): void
    {
        $result = $this->check([['job_code' => 'sitemap_generate', 'status' => 'success', 'count' => '7']], [[]],
            [$this->ago(40), '0'], self::NOW - 300);
        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame(['runs' => 7, 'errors' => 0], $result->counts);
        $this->assertSame([], $result->causes);
        $this->assertSame(['last' => self::NOW, 'failing' => []], $this->state->get('cursor:cron'));
    }

    public function testEveryQueryUsesTheJobCodeStatusScheduledAtIndex(): void
    {
        $this->check([], [[]], [$this->ago(40), '0'], self::NOW - 300);
        $wheres = $this->wheres();
        $this->assertSame(4, count(array_keys($wheres, 'job_code IN (?)', true)), 'job_code first, every query');
        $this->assertCount(4, array_filter($wheres, fn ($where) => str_starts_with((string)$where, 'scheduled_at >=')));
        $readsMessages = fn ($call) => in_array('messages', (array)($call[2] ?? []), true);
        $this->assertSame([], array_filter($this->calls, $readsMessages), 'no error text without repeated failures');
    }

    public function testCronNotStartedAtAll(): void
    {
        $result = $this->check([], [[]], [$this->ago(30 * 60), '0', '0'], self::NOW - 300);
        $this->assertSame([CheckResult::FAIL, 'Cron stopped'], [$result->status, $result->title]);
        $this->assertSame('no job has run since {t:' . (self::NOW - 30 * 60) . '} (30 min)', $result->detail,
            'the last job\'s time, shown in each recipient\'s time zone');
        $this->assertSame(['cron_not_started'], $this->causes($result));
        $this->assertSame('nothing scheduled or run since {t:' . (self::NOW - 30 * 60) . '} (30 min): cron is not'
            . ' started', $result->causes[0]->detail, 'the real gap, not the 10-minute threshold');
        $this->assertStringContainsString('bin/magento cron:install', $result->causes[0]->hint);
        $none = $this->check([], [[]], [null, '0', '0'], self::NOW - 300);
        $this->assertSame('no job has run for over 65 min', $none->detail);
        $this->assertSame('nothing scheduled or run for over 65 min: cron is not started', $none->causes[0]->detail);
    }

    public function testScheduledButNotStarted(): void
    {
        $result = $this->check([], [[]], [$this->ago(12 * 60), '25', '0'], self::NOW - 300);
        $this->assertSame(['cron_not_running'], $this->causes($result));
    }

    public function testAStuckJobAndItsWaitingGroup(): void
    {
        $result = $this->check([], [['indexer_reindex_all_invalid' => $this->ago(3 * 3600)]],
            ['12', $this->ago(30), '0'], self::NOW - 300, ['indexer_reindex_all_invalid', 'sitemap_generate']);
        $this->assertSame([CheckResult::FAIL, 'Cron jobs stuck'], [$result->status, $result->title]);
        $this->assertSame(['cron_job_stuck', 'cron_group_waiting'], $this->causes($result));
        $this->assertSame('job indexer_reindex_all_invalid running for 3 h (since {t:' . (self::NOW - 10800) . '})',
            $result->causes[0]->detail);
        $this->assertSame('12 other jobs in its group are waiting', $result->causes[1]->detail);
        $this->assertStringContainsString('restart cron', $result->causes[0]->hint);
        $this->assertContains(['indexer_update_all_views'], array_map(fn ($c) => $c[2] ?? null, array_filter(
            $this->calls, fn ($c) => $c[0] === 'where' && $c[1] === 'job_code IN (?)')), 'its group only');
    }

    public function testAJobFailingRepeatedlyWithItsErrorMasked(): void
    {
        $result = $this->check([['job_code' => 'sitemap_generate', 'status' => 'error', 'count' => '2']],
            [[], ['sitemap_generate' => '4']],
            [$this->ago(30), "Could not write /var/www/html/pub/sitemap.xml for jane@example.com\n#0 trace", '0'],
            self::NOW - 300, self::JOBS, [], ['general/include_log_message' => '1']);
        $this->assertSame([CheckResult::WARN, 'Cron job errors'], [$result->status, $result->title]);
        $this->assertSame('2 new cron errors (sitemap_generate x2)', $result->detail);
        $this->assertSame(['cron_job_failing'], $this->causes($result));
        $this->assertSame('job sitemap_generate failed 4 times in 2 h: Could not write ***.xml for ***',
            $result->causes[0]->detail);
        $this->assertSame(['runs' => 0, 'errors' => 2, 'errors:sitemap_generate' => 2], $result->counts);
    }

    public function testWithTheLogMessageOffAJobsErrorIsNotRead(): void
    {
        $result = $this->check([['job_code' => 'sitemap_generate', 'status' => 'error', 'count' => '2']],
            [[], ['sitemap_generate' => '4']], [$this->ago(30), "Could not write for jane doe\n#0 trace", '0'],
            self::NOW - 300, self::JOBS, [], ['general/include_log_message' => '0']);
        $this->assertSame('job sitemap_generate failed 4 times in 2 h', $result->causes[0]->detail);
        $readsMessages = fn ($call) => in_array('messages', (array)($call[2] ?? []), true);
        $this->assertSame([], array_filter($this->calls, $readsMessages), 'cron_schedule.messages not read');
    }

    public function testManyMissedJobsWarn(): void
    {
        $result = $this->check([], [[]], [$this->ago(30), '14'], self::NOW - 300);
        $this->assertSame([CheckResult::WARN, 'Cron jobs missed'], [$result->status, $result->title]);
        $this->assertSame(['cron_missed'], $this->causes($result));
        $this->assertSame(CheckResult::OK, $this->check([], [[]], [$this->ago(30), '9'], self::NOW - 300)->status);
    }

    public function testCronBackAfterAGapSaysWhenItWasDownNotThatItStopped(): void
    {
        $result = $this->check([], [[]], [$this->ago(7 * 3600), '0'], self::NOW - 7 * 3600);
        $this->assertSame(CheckResult::OK, $result->status, 'this check runs: cron is back - not "Cron stopped"'
            . ' because the other jobs have not run yet');
        $this->assertSame(['Cron was down from {t:' . (self::NOW - 7 * 3600) . '} to {t:' . self::NOW . '} (7 h);'
            . ' it runs again.', 'Check: Why did cron stop - the server restarted, the cron container or the crontab?'
            . ' The server\'s logs of that time say.'], $result->notices, 'one notice, at once');
        $short = $this->check([], [[]], [$this->ago(30), '0'], self::NOW - 10 * 60);
        $this->assertSame([], $short->notices, 'a run 10 minutes after the last: no gap');
        $off = $this->check([], [[]], [$this->ago(30), '0'], self::NOW - 25 * 3600);
        $this->assertSame([], $off->notices, 'the module was off for over a day: a fresh start');
    }

    public function testOnlyThisModulesJobIsNotActivity(): void
    {
        $result = $this->check([], [[]], ['0'], self::NOW - 300, [Cron::OWN_JOB]);
        $this->assertSame(CheckResult::OK, $result->status, 'no other job configured: nothing to compare');
    }

    public function testAFailingJobStaysAWarningUntilItSucceeds(): void
    {
        $error = [['job_code' => 'sitemap_generate', 'status' => 'error', 'count' => '1']];
        $this->check($error, [[], []], [$this->ago(30), '0'], self::NOW - 300);
        $this->assertSame(['sitemap_generate' => self::NOW], $this->state->get('cursor:cron')['failing']);
        $quiet = $this->check([], [[], []], [$this->ago(30), '0'], self::NOW - 300, self::JOBS,
            ['sitemap_generate' => self::NOW - 600]);
        $this->assertSame([CheckResult::WARN, 'Cron job errors'], [$quiet->status, $quiet->title]);
        $this->assertSame('sitemap_generate failing since {t:' . (self::NOW - 600) . '} (no successful run since)',
            $quiet->detail);
        $fixed = $this->check([], [[], ['sitemap_generate' => $this->ago(60)]], [$this->ago(30), '0'],
            self::NOW - 300, self::JOBS, ['sitemap_generate' => self::NOW - 600]);
        $this->assertSame(CheckResult::OK, $fixed->status, 'it ran successfully after its error');
        $old = $this->check([], [[]], [$this->ago(30), '0'], self::NOW - 300, self::JOBS,
            ['sitemap_generate' => self::NOW - 3601]);
        $this->assertSame(CheckResult::OK, $old->status, 'quiet for over an hour: no query, fixed');
    }
}
