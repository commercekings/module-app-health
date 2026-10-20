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

    private const NOW = 1792483200;

    private RunState $state;

    /**
     * @param array<int, array<string, mixed>> $finished rows of job_code, status, count
     * @param array<string, string> $running job code => executed_at
     */
    private function check(array $finished, array $running, ?string $lastActivity, ?int $lastRun = null,
        array $jobs = ['catalog_index_refresh_price', 'sitemap_generate', Cron::OWN_JOB]): CheckResult
    {
        $store = (new MemoryStore())->seed($lastRun ? ['cursor:cron' => ['last' => $lastRun]] : []);
        $this->state = new RunState($store);
        $this->state->begin();
        $cronConfig = $this->createStub(ConfigInterface::class);
        $cronConfig->method('getJobs')->willReturn(['default' => array_fill_keys($jobs, []), 'index' => []]);
        $db = $this->db(['fetchAll' => [$finished], 'fetchPairs' => [$running], 'fetchOne' => [$lastActivity]]);
        return (new Cron($this->resource($db), $cronConfig, $this->config(['cron/stale_minutes' => '5',
            'cron/running_minutes' => '60']), $this->state, new Clock(self::NOW)))->run();
    }

    public function testAHealthyCronIsOkWithItsRuns(): void
    {
        $result = $this->check([['job_code' => 'sitemap_generate', 'status' => 'success', 'count' => '7']], [],
            gmdate('Y-m-d H:i:s', self::NOW - 40), self::NOW - 300);
        $this->assertSame(CheckResult::OK, $result->status);
        $this->assertSame(['runs' => 7, 'errors' => 0], $result->counts);
        $this->assertSame(['last' => self::NOW], $this->state->get('cursor:cron'));
    }

    public function testEveryQueryUsesTheJobCodeStatusScheduledAtIndex(): void
    {
        $this->check([], [], gmdate('Y-m-d H:i:s', self::NOW - 40), self::NOW - 300);
        $wheres = $this->wheres();
        $this->assertSame(3, count(array_keys($wheres, 'job_code IN (?)', true)), 'job_code first, every query');
        $this->assertCount(3, array_filter($wheres, fn ($where) => str_starts_with((string)$where, 'scheduled_at >=')));
        $this->assertCount(3, array_filter($wheres, fn ($where) => str_starts_with((string)$where, 'status')));
        $readsMessages = fn ($call) => in_array('messages', (array)($call[2] ?? []), true);
        $this->assertSame([], array_filter($this->calls, $readsMessages), 'the error text is never read');
    }

    public function testNoOtherJobForTooLongFails(): void
    {
        $result = $this->check([], [], gmdate('Y-m-d H:i:s', self::NOW - 12 * 60), self::NOW - 300);
        $this->assertSame(CheckResult::FAIL, $result->status);
        $this->assertSame('no job has run since {t:' . (self::NOW - 12 * 60) . '}', $result->detail,
            'the last job\'s time, shown in each recipient\'s time zone');
        $none = $this->check([], [], null, self::NOW - 300);
        $this->assertSame('no job has run for over 65 min', $none->detail);
    }

    public function testALongRunningJobFails(): void
    {
        $result = $this->check([], ['sitemap_generate' => gmdate('Y-m-d H:i:s', self::NOW - 7200)],
            gmdate('Y-m-d H:i:s', self::NOW - 30), self::NOW - 300);
        $this->assertSame(CheckResult::FAIL, $result->status);
        $this->assertSame('sitemap_generate running for 2 h', $result->detail);
    }

    public function testNewErrorsWarnWithJobCodesAndCounts(): void
    {
        $result = $this->check([['job_code' => 'sitemap_generate', 'status' => 'error', 'count' => '2'],
            ['job_code' => 'catalog_index_refresh_price', 'status' => 'success', 'count' => '3']], [],
            gmdate('Y-m-d H:i:s', self::NOW - 30), self::NOW - 300);
        $this->assertSame(CheckResult::WARN, $result->status);
        $this->assertSame('2 new cron errors (sitemap_generate x2)', $result->detail);
        $this->assertSame(['runs' => 3, 'errors' => 2, 'errors:sitemap_generate' => 2], $result->counts);
    }

    public function testCronBackAfterAGapWarns(): void
    {
        $result = $this->check([], [], gmdate('Y-m-d H:i:s', self::NOW - 30), self::NOW - 47 * 60);
        $this->assertSame(CheckResult::WARN, $result->status);
        $this->assertSame('this check did not run for 47 min (cron was down)', $result->detail);
        $off = $this->check([], [], gmdate('Y-m-d H:i:s', self::NOW - 30), self::NOW - 25 * 3600);
        $this->assertSame(CheckResult::OK, $off->status, 'the module was off for over a day: a fresh start');
    }

    public function testOnlyThisModulesJobIsNotActivity(): void
    {
        $result = $this->check([], [], null, self::NOW - 300, [Cron::OWN_JOB]);
        $this->assertSame(CheckResult::OK, $result->status, 'no other job configured: nothing to compare');
    }
}
