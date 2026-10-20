<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\Notification\Status;
use CommerceKings\AppHealth\Model\Notification\TeamMessage;
use CommerceKings\AppHealth\Model\Runner;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Model\Status\DefaultPolicy;
use CommerceKings\AppHealth\Model\StatusNow;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Model\Targets;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Pool;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Api\SenderInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * "Send status now": a snapshot's results as the hourly status says them, posted at once to the global recipient;
 * the hourly status's delivery state only read, the run state never committed.
 */
class StatusNowTest extends TestCase
{
    use Pool;
    use Settings;

    private const NOW = 1792483200; // 08:00 UTC

    private MemoryStore $store;
    /** @var Delivery[] */
    private array $sent = [];
    private ?Target $target;
    /** @var array<string, mixed> what Runner::run(snapshot: true) returns */
    private array $snapshot;
    private \Throwable|string $answer = SenderInterface::SENT;
    private bool $snapshotTaken = false;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->target = new Target(0, 1, 'slack', 'C0123', 'Shop', 'UTC');
        $this->snapshot = ['ran' => [], 'skipped' => [], 'queued' => [], 'complete' => true, 'degraded' => false,
            'ms' => 1.0, 'checks' => [
                0 => ['cron' => ['status' => 'fail', 'detail' => 'no job for 65 min'],
                    'caches' => ['status' => 'warn', 'detail' => 'config invalidated for 2 h'],
                    'external' => ['status' => 'ok', 'idle' => true,
                        'detail' => 'not set up (no status file from a server yet)']],
                2 => ['email_disabled' => ['status' => 'ok']],
            ], 'notices' => []];
    }

    private function statusNow(): StatusNow
    {
        $pool = $this->pool(['cron' => 'Cron', 'caches' => 'Caches', 'external' => 'Server status'],
            ['email_disabled' => 'Email sending']);
        $targets = $this->createStub(Targets::class);
        $targets->method('shopWide')->willReturnCallback(fn () => $this->target);
        $state = new RunState($this->store);
        $runner = $this->createStub(Runner::class);
        $runner->method('run')->willReturnCallback(function (bool $snapshot = false) use ($state) {
            $this->assertTrue($snapshot, 'only ever a snapshot');
            $this->snapshotTaken = true;
            $state->begin(); // as the runner does; the snapshot's own errors would be in it too
            return $this->snapshot;
        });
        $deliveryState = $this->createMock(DeliveryStateInterface::class);
        $deliveryState->method('load')->with('app_health_status', 0)
            ->willReturn(['sent_at' => gmdate('Y-m-d H:i:s', self::NOW - 1800)]);
        $deliveryState->expects($this->never())->method('ensure');
        $sender = $this->createStub(SenderInterface::class);
        $sender->method('send')->willReturnCallback(function (Delivery $delivery) {
            $this->sent[] = $delivery;
            if ($this->answer instanceof \Throwable) {
                throw $this->answer;
            }
            return $this->answer;
        });
        $config = $this->config();
        $status = new Status($this->createStub(TeamMessage::class), $this->store,
            new Messages($pool, new Clock(self::NOW)), $pool, $config, new Clock(self::NOW),
            new DefaultPolicy($config));
        return new StatusNow($targets, $runner, $state, $status, $config, $deliveryState, $sender,
            new Clock(self::NOW));
    }

    public function testTheChecksAsTheyAreNowArePostedToTheGlobalRecipient(): void
    {
        $this->store->seed([
            Fingerprints::KEY => ['abc' => ['label' => 'Unable to send order', 'level' => 'ERROR']],
            // [time, fingerprint, count, new]: one before the last hourly status, two after it
            Fingerprints::ERRORS_KEY => [[self::NOW - 3600, 'abc', 5, true], [self::NOW - 600, 'abc', 2, false],
                [self::NOW - 60, 'abc', 1, false]],
        ]);
        $rows = $this->store->rows;
        $this->assertSame(['C0123', 'slack'], $this->statusNow()->send());
        $delivery = $this->sent[0];
        $this->assertSame(['C0123', 'slack', false, false, 'app_health_status_now'], [$delivery->recipient,
            $delivery->channel, $delivery->useCooldown, $delivery->stoppable, $delivery->type]);
        $text = ($delivery->content)()->text;
        $this->assertStringStartsWith("*Status now · Shop*\n\n1 problem: Cron\n"
            . "Server status: not set up (no status file from a server yet)\n"
            . 'Warnings: Caches (config invalidated for 2 h)', $text);
        $this->assertStringContainsString("New errors this hour: 3\n• Unable to send order (3×)", $text,
            'the errors since the last hourly status, as it would say them');
        $this->assertSame($rows, $this->store->rows, 'nothing written');
    }

    public function testAnIncompleteSnapshotSaysWhat(): void
    {
        $this->snapshot['complete'] = false;
        $this->snapshot['skipped'] = ['queues'];
        $this->statusNow()->send();
        $this->assertStringEndsWith("\n\nNot checked this time (time budget): queues.",
            ($this->sent[0]->content)()->text);
    }

    public function testTheAdminSeesWhyNot(): void
    {
        $this->target = null;
        $this->assertFalse($this->statusNow()->isAvailable());
        foreach ([
            [null, SenderInterface::SENT, 'Set a channel and recipient under Alerts first.'],
            [$t = new Target(0, 1, 'slack', 'nope', 'Shop', 'UTC'), new \InvalidArgumentException('x'),
                '"nope" is not a recipient the "slack" channel takes.'],
            [$t, new \RuntimeException('channel_not_found'), 'Not posted (RuntimeException): channel_not_found'],
            [$t, SenderInterface::COOLDOWN, 'Not posted: recipient cooldown.'],
        ] as [$target, $answer, $message]) {
            [$this->target, $this->answer, $this->snapshotTaken] = [$target, $answer, false];
            try {
                $this->statusNow()->send();
                $this->fail('posted');
            } catch (LocalizedException $e) {
                $this->assertSame($message, $e->getMessage());
            }
            $this->assertSame($target !== null, $this->snapshotTaken, 'no run without a recipient');
        }
    }
}
