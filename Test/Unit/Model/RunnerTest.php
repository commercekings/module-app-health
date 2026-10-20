<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\AlertPlan;
use CommerceKings\AppHealth\Model\Heartbeat;
use CommerceKings\AppHealth\Model\Notification\TeamMessage;
use CommerceKings\AppHealth\Model\Runner;
use CommerceKings\AppHealth\Model\Status\DefaultPolicy;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Model\Targets;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Pool;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** The run and the change-only alerts end to end, over the state as JSON rows. */
class RunnerTest extends TestCase
{
    use Pool;
    use Settings;

    private MemoryStore $store;
    private Clock $clock;
    /** @var int[] scopes queued */
    private array $queued = [];
    private int $pings = 0;
    /** @var string[] */
    private array $logs = [];
    private bool $shopWide = true;
    /** @var int[] */
    private array $websites = [1];
    private bool $heartbeat = false;
    private bool $queueThrows = false;
    /** The hourly status's sent_at (UTC), null: never sent; queued: its queue() calls and their conditions. */
    private ?string $statusSentAt = 'recent';
    /** @var string[] */
    private array $statusQueued = [];
    /** @var int[] the scopes whose status row was read */
    private array $statusLoaded = [];
    private ?\CommerceKings\AppHealth\Api\StatusPolicyInterface $policy = null;
    /** @var array<string, mixed> */
    private array $settings = [];

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->clock = new Clock();
    }

    private function runner(): Runner
    {
        $targets = $this->createStub(Targets::class);
        $targets->method('shopWide')->willReturn($this->shopWide
            ? new Target(0, 1, 'slack', 'C0', 'Shop', 'UTC') : null);
        $targets->method('websites')->willReturn(array_combine($this->websites, array_map(
            fn ($id) => new Target($id, $id, 'slack', 'C' . $id, 'Shop', 'UTC'), $this->websites)) ?: []);
        $teamMessage = $this->createStub(TeamMessage::class);
        $teamMessage->method('queue')->willReturnCallback(function ($type, $scope, $condition = '') {
            if ($type === 'app_health_status') {
                $this->statusQueued[] = $scope . ' ' . $condition;
                return true;
            }
            $this->assertSame('app_health_alert', $type);
            if ($this->queueThrows) {
                throw new \RuntimeException('queue down');
            }
            $this->queued[] = $scope;
            return true;
        });
        $heartbeat = $this->createStub(Heartbeat::class);
        $heartbeat->method('isConfigured')->willReturnCallback(fn () => $this->heartbeat);
        $heartbeat->method('ping')->willReturnCallback(function () {
            $this->pings++;
            return true;
        });
        $logger = $this->createStub(LoggerInterface::class);
        foreach (['error', 'warning'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message) {
                $this->logs[] = $message;
            });
        }
        $deliveryState = $this->createStub(\CommerceKings\Notification\Api\DeliveryStateInterface::class);
        $deliveryState->method('load')->willReturnCallback(function ($type, $id) {
            $this->assertSame('app_health_status', $type);
            $this->statusLoaded[] = $id;
            return $this->statusSentAt === null ? null : ['sent_at' => $this->statusSentAt === 'recent'
                ? gmdate('Y-m-d H:i:s', $this->clock->now - 600) : $this->statusSentAt];
        });
        return new Runner($this->pool(['cron' => 'Cron', 'caches' => 'Caches'], ['email_disabled' => 'Email']),
            $this->config($this->settings), new RunState($this->store), $this->store, $targets, $teamMessage,
            $heartbeat, $this->clock, $logger, $deliveryState, $this->policy ?? new DefaultPolicy($this->config(
                $this->settings)), 0.05);
    }

    /** @return array<string, mixed> */
    private function run5(): array
    {
        $this->queued = [];
        $report = $this->runner()->run();
        $this->assertSame($this->queued, $report['queued'], 'what the report says was queued, was');
        $this->clock->now += 300;
        return $report;
    }

    public function testAFailIsAlertedOnceToItsScopeThenRecoveredOnce(): void
    {
        $this->settings = ['general/recover_runs' => '1'];
        $this->assertSame([], $this->run5()['queued'], 'all ok: nothing');
        $this->results['cron'] = CheckResult::fail('no job has run for 12 min');
        $this->assertSame([0], $this->run5()['queued'], 'a shop-wide check: the default scope');
        // the consumer sends it and records what it told
        $this->store->seed(['alerted:0' => ['cron' => ['status' => 'fail', 'at' => $this->clock->now - 300]]]);
        $this->assertSame([], $this->run5()['queued'], 'still failing: no news');
        $this->results['cron'] = CheckResult::ok();
        $this->assertSame([0], $this->run5()['queued'], 'recovered');
    }

    public function testANoticeAloneIsNewsOnceAndKeptForTheConsumer(): void
    {
        $this->results['caches'] = new CheckResult('ok', '', [], ['NEW ERROR main: x x1 #12345678']);
        $this->assertSame([0], $this->run5()['queued']);
        $row = $this->store->row(AlertPlan::NOTICES . '0');
        $this->assertSame([[1, $this->clock->now - 300, 'caches', 'NEW ERROR main: x x1 #12345678'],
            [2, $this->clock->now - 300, 'caches', 'Check: Look at Caches.']], $row['items'], 'with what to do');
        $this->results['caches'] = CheckResult::ok();
        $this->store->seed([AlertPlan::NOTICED . '0' => ['caches' => ['id' => 2, 'at' => 0]]]); // the consumer told it
        $this->assertSame([], $this->run5()['queued']);
    }

        public function testAWebsiteCheckAlertsItsWebsite(): void
    {
        $this->websites = [1, 2];
        $this->results['email_disabled'] = [1 => CheckResult::ok(), 2 => CheckResult::fail('emails are disabled')];
        $report = $this->run5();
        $this->assertSame([2], $report['queued']);
        $checks = $this->store->row('checks');
        $this->assertSame(['cron', 'caches'], array_keys($checks[0]));
        $this->assertSame('fail', $checks[2]['email_disabled']['status']);
        $this->assertSame('ok', $checks[1]['email_disabled']['status']);
    }

    public function testASnapshotReturnsTheResultsAndChangesNothing(): void
    {
        $this->heartbeat = true;
        $this->websites = [1, 2];
        $this->run5();
        $rows = $this->store->rows;
        $statements = $this->store->statements;
        [$pings, $queued, $statusQueued] = [$this->pings, $this->queued, $this->statusQueued];
        $this->results['cron'] = new CheckResult('fail', 'cron stopped', [], ['NEW ERROR x']);
        $this->results['email_disabled'] = [1 => CheckResult::ok(), 2 => CheckResult::warn('emails are off')];
        $this->clock->now += 3600;
        $snapshot = $this->runner()->run(snapshot: true);
        $this->assertSame(['fail', 'ok', 'warn'], [$snapshot['checks'][0]['cron']['status'],
            $snapshot['checks'][1]['email_disabled']['status'], $snapshot['checks'][2]['email_disabled']['status']]);
        $this->assertSame('NEW ERROR x', $snapshot['notices'][0]['cron'][0]);
        $this->assertTrue($snapshot['complete']);
        $this->assertSame($rows, $this->store->rows, 'nothing stored: no checks, tally, notices or status row');
        $this->assertSame(1, $this->store->statements - $statements, 'the one read');
        $this->assertSame([$pings, $queued, $statusQueued], [$this->pings, $this->queued, $this->statusQueued],
            'nothing queued, no alert and no hourly status, no ping');
        $this->runner()->run();
        $this->assertSame($this->clock->now, $this->store->row('checks')[0]['cron']['since'],
            'the next run sees the change as new: the snapshot told no one');
        $this->assertContains(0, $this->queued, 'and alerts it');
    }

    public function testAFailingCheckCountsAsCouldNotRunWhereItRanLastTime(): void
    {
        $this->websites = [1, 2];
        $this->run5();
        $this->results['email_disabled'] = new \RuntimeException('down');
        $this->results['cron'] = new \RuntimeException('down');
        $checks = $this->runAndRead();
        $this->assertSame(['warn', 'warn'], [$checks[1]['email_disabled']['status'],
            $checks[2]['email_disabled']['status']], 'the websites it answered for before');
        $this->assertSame('warn', $checks[0]['cron']['status']);
        $this->assertArrayNotHasKey('email_disabled', $checks[0], 'not the whole shop');
        $this->assertStringContainsString('could not run', $checks[1]['email_disabled']['detail']);
    }

    public function testAWebsiteLeftOutCountsAsCouldNotRun(): void
    {
        $this->websites = [1, 2];
        $this->results['email_disabled'] = [1 => CheckResult::ok()];
        $checks = $this->runAndRead();
        $this->assertSame(['ok', 'warn'], [$checks[1]['email_disabled']['status'],
            $checks[2]['email_disabled']['status']]);
    }

    /** @return array<mixed> the "checks" row after a run */
    private function runAndRead(): array
    {
        $this->run5();
        return $this->store->row('checks');
    }

    public function testRunsInARowWithTheSameStatusAreCounted(): void
    {
        $this->results['cron'] = CheckResult::fail('x');
        $this->run5();
        $this->run5();
        $this->assertSame(2, $this->store->row('checks')[0]['cron']['runs']);
        $this->results['cron'] = CheckResult::ok();
        $this->store->seed(['alerted:0' => ['cron' => ['status' => 'fail', 'at' => 1]]]);
        $this->assertSame([0], $this->run5()['queued'], 'cron, a state: resolved at its first ok run');
        $this->assertSame(1, $this->store->row('checks')[0]['cron']['runs']);
        $this->run5();
        $this->assertSame(2, $this->store->row('checks')[0]['cron']['runs']);
    }

    public function testSharedProblemsGoToWebsiteChannelsOnlyWhenAsked(): void
    {
        $this->results['cron'] = CheckResult::fail('no job has run');
        $this->assertSame([0], $this->run5()['queued'], 'off by default: the global channel only');
        $this->store->seed(['alerted:0' => ['cron' => ['status' => 'fail', 'at' => $this->clock->now]]]);
        $this->settings = ['general/shared_to_websites' => '1'];
        $this->assertSame([1], $this->run5()['queued'], 'on: website 1, a channel of its own, gets it too');
    }

    public function testTheTimeSinceTheStatusChangedIsKept(): void
    {
        $start = $this->clock->now;
        $this->results['cron'] = CheckResult::fail('x');
        $this->run5();
        $this->run5();
        $this->assertSame($start, $this->store->row('checks')[0]['cron']['since']);
        $this->results['cron'] = CheckResult::warn('y');
        $this->run5();
        $this->assertSame($start + 600, $this->store->row('checks')[0]['cron']['since']);
    }

    public function testCountsAndTheWorstStatusAddUpPerHour(): void
    {
        $this->results['cron'] = CheckResult::ok('', ['runs' => 3]);
        $this->run5();
        $this->results['cron'] = CheckResult::warn('e', ['runs' => 2, 'errors:x' => 1]);
        $this->run5();
        $this->results['cron'] = CheckResult::ok('', ['runs' => 4]);
        $this->run5();
        $tally = $this->store->row('tally:' . gmdate('YmdH', $this->clock->now));
        $this->assertSame(['w' => 1, 'c' => ['runs' => 9, 'errors:x' => 1]], $tally[0]['cron']);
    }

    public function testAcrossTheHourAProblemIsAlertedAndAFailIsNotRepeated(): void
    {
        $this->settings = ['general/recover_runs' => '1'];
        $this->clock->now = 1792504799; // 13:59:59 UTC
        $this->assertSame([], $this->runner()->run()['queued'], 'all ok before the hour');
        $this->clock->now += 2; // 14:00:01: the hour's first run, which also cleans up the old tallies
        $this->results['cron'] = CheckResult::fail('no job has run since 13:30');
        $this->assertSame([0], $this->runner()->run()['queued'], 'a new problem right after the hour');
        $this->assertNotNull($this->store->row('tally:2026102013'), 'the last hour\'s tally kept');
        $this->assertSame(2, $this->store->row('tally:2026102014')[0]['cron']['w']);

        $this->clock->now = 1792508399; // 14:59:59
        $this->store->seed(['alerted:0' => ['cron' => ['status' => 'fail', 'at' => 1792504801]]]);
        $this->results['caches'] = CheckResult::fail('config disabled');
        $this->assertSame([0], $this->runner()->run()['queued'], 'another problem before the next hour');
        $this->store->seed(['alerted:0' => ['cron' => ['status' => 'fail', 'at' => 1792504801],
            'caches' => ['status' => 'fail', 'at' => 1792508399]]]);
        $this->clock->now += 2; // 15:00:01
        $this->assertSame([], $this->runner()->run()['queued'], 'both told: nothing new after the hour');
        $this->results['cron'] = CheckResult::ok();
        $this->assertSame([0], $this->runner()->run()['queued'], 'a recovery after the hour');
    }

    public function testOneLoadAndOneWritePerRun(): void
    {
        $this->clock->now += 600; // not the hour's first run (no cleanup)
        $this->runner()->run();
        $this->assertSame(2, $this->store->statements);
    }

    public function testADisabledCheckDoesNotRunAndIsForgotten(): void
    {
        $this->results['caches'] = CheckResult::fail('full_page disabled');
        $this->run5();
        $this->settings = ['caches/enabled' => '0'];
        $this->run5();
        $this->assertSame(1, $this->runs['caches']);
        $this->assertArrayNotHasKey('caches', $this->store->row('checks')[0]);
    }

    public function testAThrowingCheckWarnsLogsItsClassOnlyAndTheRunStillPings(): void
    {
        $this->heartbeat = true;
        $this->results['cron'] = new \RuntimeException(
            'SQLSTATE: SELECT * FROM customer WHERE email = jane@example.com'
        );
        $report = $this->run5();
        $this->assertSame([false, true], [$report['complete'], $report['degraded']]);
        $this->assertSame(1, $this->pings, 'the run finished: cron works, a check is degraded');
        $this->assertSame('warn', $this->store->row('checks')[0]['cron']['status']);
        $this->assertStringNotContainsString('jane', implode("\n", $this->logs) . json_encode($this->store->rows));
        $this->assertStringContainsString('RuntimeException', implode("\n", $this->logs));
        $this->results['cron'] = CheckResult::ok();
        $this->assertFalse($this->run5()['degraded']);
        $this->assertSame(2, $this->pings);
    }

    public function testTheHourlyStatusIsQueuedOnEveryRunWhileDue(): void
    {
        $this->statusSentAt = null;
        $this->run5();
        $this->run5();
        $this->assertSame(['0 (sent_at IS NULL OR sent_at < NOW() - INTERVAL 58 MINUTE)',
            '0 (sent_at IS NULL OR sent_at < NOW() - INTERVAL 58 MINUTE)'], $this->statusQueued,
            'not sent yet: every run (queue() itself skips one already queued or waiting for a retry)');
        $this->statusSentAt = 'recent';
        $this->run5();
        $this->assertCount(2, $this->statusQueued, 'sent 10 minutes ago: not due, only the row read');
        $this->statusSentAt = gmdate('Y-m-d H:i:s', $this->clock->now - 59 * 60);
        $this->run5();
        $this->assertCount(3, $this->statusQueued, 'due again');
        $this->settings = ['general/hourly_status' => '0'];
        $this->statusLoaded = [];
        $this->run5();
        $this->assertSame([3, []], [count($this->statusQueued), $this->statusLoaded], 'off: not even read');
    }

    public function testTheHeartbeatStopsOnlyWhenTheStatusIsLateBeyondTheGrace(): void
    {
        $this->heartbeat = true;
        $this->assertSame('pinged', $this->run5()['heartbeat'], 'the last status 10 min ago');
        $this->statusSentAt = gmdate('Y-m-d H:i:s', $this->clock->now - 61 * 60);
        $this->assertSame('pinged', $this->run5()['heartbeat'], 'due now: queued, within the grace');
        $this->assertSame('pinged', $this->run5()['heartbeat'], 'a retry 5 min later: still within it');
        $this->statusSentAt = 'recent'; // the retry went out
        $this->assertSame('pinged', $this->run5()['heartbeat'], 'a transient failure costs no pings');
        $this->statusSentAt = gmdate('Y-m-d H:i:s', $this->clock->now - 61 * 60);
        $this->run5();
        $this->run5();
        $this->assertSame('held: no hourly status went out recently', $this->run5()['heartbeat'],
            'due for 10 minutes and still not out: the sending path is broken');
        $this->settings = ['general/hourly_status' => '0'];
        $this->assertSame('pinged', $this->run5()['heartbeat'], 'without the hourly status: every finished run');
    }

    public function testNoHeartbeatWhenAnAlertCannotBeQueued(): void
    {
        $this->heartbeat = true;
        $this->queueThrows = true;
        $this->results['cron'] = CheckResult::fail('x');
        $this->run5();
        $this->assertSame(0, $this->pings, 'alerts can\'t go out: the outside service should alert');
        $this->assertStringContainsString('alert for the shop not queued (RuntimeException)',
            implode("\n", $this->logs));
    }

    public function testAWebsiteCheckMissingAWebsitesResultWarnsForIt(): void
    {
        $this->websites = [1, 2];
        $this->results['email_disabled'] = [1 => CheckResult::ok()];
        $this->run5();
        $this->assertSame('warn', $this->store->row('checks')[2]['email_disabled']['status']);
    }

    public function testTheTimeBudgetSkipsTheRemainingChecksAndKeepsTheirResults(): void
    {
        $this->heartbeat = true;
        $this->results['caches'] = CheckResult::fail('full_page disabled');
        $this->run5();
        // a cron check that takes the whole budget
        $this->results['cron'] = function () {
            usleep(60000);
            return CheckResult::ok();
        };
        $this->results['caches'] = CheckResult::ok();
        $report = $this->run5();
        $this->assertSame(['caches', 'email_disabled'], $report['skipped']);
        $this->assertSame([false, true], [$report['complete'], $report['degraded']]);
        $this->assertSame(2, $this->pings, 'a run cut by the budget finished too');
        $this->assertSame(1, $this->runs['caches']);
        $this->assertSame('fail', $this->store->row('checks')[0]['caches']['status'], 'the last result stays');
        $this->assertSame('ok', $this->store->row('checks')[1]['email_disabled']['status']);
        $this->assertStringContainsString('time budget used up', implode("\n", $this->logs));
    }

    public function testNothingRunsWhileAlertsAreOffAndNoHeartbeatIsSet(): void
    {
        $this->shopWide = false;
        $this->websites = [];
        $this->assertSame([], $this->run5()['ran']);
        $this->assertSame(0, $this->store->statements);
        $this->heartbeat = true;
        $report = $this->run5();
        $this->assertSame(['cron', 'caches'], array_keys($report['ran']),
            'the heartbeat needs a run; no website checks');
        $this->assertSame(1, $this->pings);
    }
}
