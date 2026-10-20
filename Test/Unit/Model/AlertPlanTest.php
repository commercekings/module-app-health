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
        $this->assertSame(['cron' => AlertPlan::PROBLEM], $changes, 'a warning is not sent at once (hourly status)');
        $told = AlertPlan::told($checks, [], $changes, self::NOW);
        $this->assertSame(['cron' => ['status' => 'fail', 'at' => self::NOW, 'since' => self::NOW]], $told);
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
        $this->assertSame(['cron' => ['status' => 'ok', 'at' => self::NOW]], $after, 'when it was resolved');
        $this->assertSame([], AlertPlan::changes($checks, $after, self::NOW + 300, self::REPEAT));
    }

    public function testAThresholdCheckRecoversAfterEnoughOkRunsAStateCheckAtOnce(): void
    {
        $told = ['system_log' => ['status' => 'fail', 'at' => self::NOW - 600],
            'maintenance' => ['status' => 'fail', 'at' => self::NOW - 600]];
        $checks = ['system_log' => ['status' => 'ok', 'runs' => 1], 'maintenance' => ['status' => 'ok', 'runs' => 1]];
        $this->assertSame(['maintenance' => AlertPlan::RECOVERED], AlertPlan::changes($checks, $told, self::NOW,
            self::REPEAT, 2), 'a spike window: one ok run is not yet; maintenance mode off: resolved');
        $checks['system_log']['runs'] = 2;
        $this->assertSame(['system_log' => AlertPlan::RECOVERED, 'maintenance' => AlertPlan::RECOVERED],
            AlertPlan::changes($checks, $told, self::NOW, self::REPEAT, 2));
        $this->assertSame(['exceptions', 'system_log', 'queues', 'email_failing', 'external'],
            AlertPlan::WINDOW_CHECKS, 'a server\'s load or memory comes and goes too');
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

    public function testTheToldRecordKeepsWhenAProblemBeganAcrossRepeats(): void
    {
        $checks = $this->checks(['cron' => 'fail']);
        $told = AlertPlan::told($checks, [], ['cron' => AlertPlan::PROBLEM], self::NOW - 7200);
        $this->assertSame(['cron' => ['status' => 'fail', 'at' => self::NOW - 7200, 'since' => self::NOW - 7200]],
            $told);
        $told = AlertPlan::told($checks, $told, ['cron' => AlertPlan::REPEAT], self::NOW);
        $this->assertSame(self::NOW - 7200, $told['cron']['since'], 'a repeat: the same problem');
        $resolved = AlertPlan::told($this->checks(['cron' => 'ok']), $told, ['cron' => AlertPlan::RECOVERED],
            self::NOW);
        $this->assertSame(['cron' => ['status' => 'ok', 'at' => self::NOW]], $resolved);
    }

    public function testAProblemBackSoonAfterItsResolvedWaitsSoItDoesNotFlap(): void
    {
        $resolved = ['external' => ['status' => 'ok', 'at' => self::NOW - 600]];
        $failing = $this->checks(['external' => 'fail']);
        $this->assertSame([], AlertPlan::changes($failing, $resolved, self::NOW, self::REPEAT),
            'failing again 10 minutes after "Resolved": not yet');
        $this->assertSame(['external' => AlertPlan::PROBLEM], AlertPlan::changes($failing, $resolved,
            self::NOW + AlertPlan::REPOST_SECONDS - 600, self::REPEAT), '30 minutes after it: told');
        $this->assertSame([], AlertPlan::changes($this->checks(['external' => 'ok']), $resolved, self::NOW,
            self::REPEAT), 'still ok: no news');
    }

    public function testAWebsiteChannelGetsItsOwnChecksAndTheSharedOnesWhenAsked(): void
    {
        $row = [0 => ['cron' => ['status' => 'fail'], 'sensitive_data' => ['status' => 'ok']],
            2 => ['email_disabled' => ['status' => 'fail']]];
        $this->assertSame(['email_disabled'], array_keys(AlertPlan::scopeChecks($row, 2, false)));
        $this->assertSame(['email_disabled', 'cron'], array_keys(AlertPlan::scopeChecks($row, 2, true)),
            'the shared ones too - never the security findings');
        $this->assertSame(['cron', 'sensitive_data'], array_keys(AlertPlan::scopeChecks($row, 0, true)));
    }

    public function testAWarningIsNotNewsAWorseProblemIs(): void
    {
        $told = ['caches' => ['status' => 'fail', 'at' => self::NOW - 60]];
        $this->assertSame(['cron' => AlertPlan::PROBLEM], AlertPlan::changes($this->checks(['cron' => 'fail',
            'caches' => 'warn', 'queues' => 'warn']), $told, self::NOW, self::REPEAT),
            'fail to warn: nothing yet (resolved when ok), ok to warn: nothing');
        $this->assertSame(['caches' => AlertPlan::RECOVERED], AlertPlan::changes($this->checks(['caches' => 'ok']),
            $told, self::NOW, self::REPEAT));
    }

    public function testAChangeNotYetToldKeepsItsOwnRecord(): void
    {
        $told = ['cron' => ['status' => 'fail', 'at' => 100], 'gone' => ['status' => 'fail', 'at' => 100]];
        $checks = $this->checks(['cron' => 'fail', 'caches' => 'fail']);
        $after = AlertPlan::told($checks, $told, ['caches' => AlertPlan::PROBLEM], self::NOW);
        $this->assertSame(['cron' => ['status' => 'fail', 'at' => 100],
            'caches' => ['status' => 'fail', 'at' => self::NOW, 'since' => self::NOW]], $after,
            'only what the message carried changes; a check no longer run is forgotten');
    }
}
