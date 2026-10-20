<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\AlertPlan;
use PHPUnit\Framework\TestCase;

/** Change-only: a problem once, "recovered" once, a fail again only after the repeat interval. */
class AlertPlanTest extends TestCase
{
    private const NOW = 1792483200;
    private const REPEAT = 6 * 3600;

    private function checks(array $statuses): array
    {
        return array_map(fn ($status) => ['status' => $status, 'detail' => '', 'since' => self::NOW], $statuses);
    }

    public function testAnOkCheckNeverToldIsNoNews(): void
    {
        $this->assertSame([], AlertPlan::changes($this->checks(['cron' => 'ok', 'caches' => 'ok']), [], self::NOW,
            self::REPEAT));
    }

    public function testAProblemIsToldOnceUntilTheRepeatInterval(): void
    {
        $checks = $this->checks(['cron' => 'fail', 'caches' => 'ok', 'queues' => 'warn']);
        $changes = AlertPlan::changes($checks, [], self::NOW, self::REPEAT);
        $this->assertSame(['cron' => AlertPlan::PROBLEM, 'queues' => AlertPlan::PROBLEM], $changes);
        $told = AlertPlan::told($checks, [], $changes, self::NOW);
        $this->assertSame(['cron' => ['status' => 'fail', 'at' => self::NOW],
            'queues' => ['status' => 'warn', 'at' => self::NOW]], $told);
        $this->assertSame([], AlertPlan::changes($checks, $told, self::NOW + self::REPEAT - 1, self::REPEAT),
            'still failing within the interval: nothing');
        $this->assertSame(['cron' => AlertPlan::REPEAT],
            AlertPlan::changes($checks, $told, self::NOW + self::REPEAT, self::REPEAT), 'a warning is not repeated');
    }

    public function testRecoveredOnceThenNothing(): void
    {
        $told = ['cron' => ['status' => 'fail', 'at' => self::NOW - 60]];
        $checks = $this->checks(['cron' => 'ok']);
        $changes = AlertPlan::changes($checks, $told, self::NOW, self::REPEAT);
        $this->assertSame(['cron' => AlertPlan::RECOVERED], $changes);
        $after = AlertPlan::told($checks, $told, $changes, self::NOW);
        $this->assertSame([], $after);
        $this->assertSame([], AlertPlan::changes($checks, $after, self::NOW + 300, self::REPEAT));
    }

    public function testRecoveredOnlyAfterEnoughOkRunsInARow(): void
    {
        $told = ['cron' => ['status' => 'fail', 'at' => self::NOW - 600]];
        $checks = ['cron' => ['status' => 'ok', 'since' => self::NOW, 'runs' => 1]];
        $this->assertSame([], AlertPlan::changes($checks, $told, self::NOW, self::REPEAT, 2), 'one ok run: not yet');
        $checks['cron']['runs'] = 2;
        $this->assertSame(['cron' => AlertPlan::RECOVERED], AlertPlan::changes($checks, $told, self::NOW,
            self::REPEAT, 2));
        $checks = ['cron' => ['status' => 'warn', 'since' => self::NOW, 'runs' => 1]];
        $this->assertSame(['cron' => AlertPlan::PROBLEM], AlertPlan::changes($checks, $told, self::NOW,
            self::REPEAT, 2), 'fail to warn is news at once');
    }

    public function testNoticesOfACheckAtMostOncePerIntervalTheRestFoldedIntoTheNext(): void
    {
        $row = AlertPlan::addNotices([], ['system_log' => ['a', 'b'], 'exceptions' => ['x']], self::NOW - 60);
        $row = AlertPlan::addNotices($row, ['system_log' => ['c']], self::NOW);
        $noticed = AlertPlan::noticed([], 'system_log', 0, self::NOW - 600); // told 10 min ago
        $this->assertSame(['exceptions' => [3 => 'x']], AlertPlan::pendingNotices($row, $noticed, self::NOW, 1800),
            'system.log waits for its 30 minutes');
        $this->assertSame([1 => 'a', 2 => 'b', 4 => 'c'], AlertPlan::pendingNotices($row, $noticed, self::NOW + 1200,
            1800)['system_log'], 'then all of them in one message');
        $noticed = AlertPlan::noticed($noticed, 'system_log', 4, self::NOW + 1200);
        $this->assertSame(['exceptions'], array_keys(AlertPlan::pendingNotices($row, $noticed, self::NOW + 4000,
            1800)));
    }

    public function testAWorseOrBetterProblemIsNews(): void
    {
        $told = ['cron' => ['status' => 'warn', 'at' => self::NOW - 60], 'caches' => ['status' => 'fail', 'at' => 0]];
        $this->assertSame(['cron' => AlertPlan::PROBLEM, 'caches' => AlertPlan::PROBLEM],
            AlertPlan::changes($this->checks(['cron' => 'fail', 'caches' => 'warn']), $told, self::NOW, self::REPEAT));
    }

    public function testAChangeNotYetToldKeepsItsOwnRecord(): void
    {
        $told = ['cron' => ['status' => 'fail', 'at' => 100], 'gone' => ['status' => 'fail', 'at' => 100]];
        $checks = $this->checks(['cron' => 'fail', 'caches' => 'fail']);
        $after = AlertPlan::told($checks, $told, ['caches' => AlertPlan::PROBLEM], self::NOW);
        $this->assertSame(['cron' => ['status' => 'fail', 'at' => 100],
            'caches' => ['status' => 'fail', 'at' => self::NOW]], $after,
            'only what the message carried changes; a check no longer run is forgotten');
    }
}
