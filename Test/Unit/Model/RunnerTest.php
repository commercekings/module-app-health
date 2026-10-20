<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Heartbeat;
use CommerceKings\AppHealth\Model\Notification\TeamMessage;
use CommerceKings\AppHealth\Model\Runner;
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
        $teamMessage->method('queue')->willReturnCallback(function ($type, $scope) {
            $this->assertSame('app_health_alert', $type);
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
        return new Runner($this->pool(['cron' => 'Cron', 'caches' => 'Caches'], ['email_disabled' => 'Email']),
            $this->config($this->settings), new RunState($this->store), $this->store, $targets, $teamMessage,
            $heartbeat, $this->clock, $logger, 0.05);
    }

    /** @return array<string, mixed> */
    private function run5(): array
    {
        $this->queued = [];
        $report = $this->runner()->run();
        $this->clock->now += 300;
        return $report;
    }

    public function testAFailIsAlertedOnceToItsScopeThenRecoveredOnce(): void
    {
        $this->assertSame([], $this->run5()['queued'], 'all ok: nothing');
        $this->results['cron'] = CheckResult::fail('no job has run for 12 min');
        $this->assertSame([0], $this->run5()['queued'], 'a shop-wide check: the default scope');
        // the consumer sends it and records what it told
        $this->store->seed(['alerted:0' => ['cron' => ['status' => 'fail', 'at' => $this->clock->now - 300]]]);
        $this->assertSame([], $this->run5()['queued'], 'still failing: no news');
        $this->results['cron'] = CheckResult::ok();
        $this->assertSame([0], $this->run5()['queued'], 'recovered');
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

    public function testAThrowingCheckWarnsLogsItsClassOnlyAndSkipsTheHeartbeat(): void
    {
        $this->heartbeat = true;
        $this->results['cron'] = new \RuntimeException(
            'SQLSTATE: SELECT * FROM customer WHERE email = jane@example.com'
        );
        $report = $this->run5();
        $this->assertFalse($report['complete']);
        $this->assertSame(0, $this->pings);
        $this->assertSame('warn', $this->store->row('checks')[0]['cron']['status']);
        $this->assertStringNotContainsString('jane', implode("\n", $this->logs) . json_encode($this->store->rows));
        $this->assertStringContainsString('RuntimeException', implode("\n", $this->logs));
        $this->results['cron'] = CheckResult::ok();
        $this->run5();
        $this->assertSame(1, $this->pings, 'a complete run pings');
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
        $this->assertFalse($report['complete']);
        $this->assertSame(1, $this->pings, 'only the first, complete run pinged');
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
