<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Notification;

use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\Notification\Status;
use CommerceKings\AppHealth\Model\Notification\TeamMessage;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Pool;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use PHPUnit\Framework\TestCase;

/** The hourly status: the checks in one line, then the log errors since the last status. */
class StatusTest extends TestCase
{
    use Pool;
    use Settings;

    private const NOW = 1792483200; // 08:00 UTC

    /** @var string[] */
    private array $sent = [];

    private function send(MemoryStore $store, ?string $sentAt = null, array $settings = []): void
    {
        $target = new Target(0, 1, 'slack', 'C0', 'Shop', 'UTC');
        $teamMessage = $this->createStub(TeamMessage::class);
        $teamMessage->method('deliver')->willReturnCallback(function ($type, $reference, \Closure $compose) use (
            $target,
            $sentAt
        ): bool {
            $this->assertSame([Status::CODE, '0'], [$type, $reference]);
            $composed = $compose($target, ['sent_at' => $sentAt]);
            if ($composed === null) {
                return false;
            }
            $this->sent[] = $composed[0];
            return true;
        });
        $pool = $this->pool(['cron' => 'Cron', 'queues' => 'Queue', 'caches' => 'Caches'],
            ['email_disabled' => 'Email sending']);
        (new Status($teamMessage, $store, new Messages($pool, new Clock(self::NOW)), $pool, $this->config($settings),
            new Clock(self::NOW)))->process('0');
    }

    public function testAllChecksOkAndNoErrors(): void
    {
        $this->send((new MemoryStore())->seed(['checks' => [0 => ['cron' => ['status' => 'ok']],
            1 => ['email_disabled' => ['status' => 'ok']]]]));
        $this->assertSame(["*Hourly status · Shop (all websites)*\n\nAll checks OK"], $this->sent);
    }

    public function testTheProblemsOfEveryScopeAndTheErrorsSinceTheLastStatus(): void
    {
        $store = (new MemoryStore())->seed([
            'checks' => [0 => ['cron' => ['status' => 'fail'], 'queues' => ['status' => 'warn'],
                'caches' => ['status' => 'ok']], 2 => ['email_disabled' => ['status' => 'fail']]],
            Fingerprints::KEY => ['aaaaaaaa' => ['label' => 'Unable to send'],
                'bbbbbbbb' => ['label' => 'Magento\\Framework\\Exception\\NoSuchEntityException']],
            Fingerprints::ERRORS_KEY => [[self::NOW - 4000, 'aaaaaaaa', 9, 1], // before the last status
                [self::NOW - 3000, 'aaaaaaaa', 2, 0], [self::NOW - 600, 'aaaaaaaa', 1, 0],
                [self::NOW - 300, 'bbbbbbbb', 5, 1]],
        ]);
        $this->send($store, gmdate('Y-m-d H:i:s', self::NOW - 3600));
        $this->assertSame(["*Hourly status · Shop (all websites)*\n\n3 problems: Cron, Queue, Email sending\n\n"
            . "New errors this hour: 8\n• NoSuchEntityException (5×, new)\n• Unable to send (3×)"], $this->sent);
    }

    public function testAtMostFiveErrorsThenCounted(): void
    {
        $errors = [];
        foreach (range(1, 7) as $i) {
            $errors[] = [self::NOW - 60, 'fp' . $i, 10 - $i, 0];
        }
        $this->send((new MemoryStore())->seed([Fingerprints::ERRORS_KEY => $errors]));
        $this->assertStringEndsWith("• #fp5 (5×)\n• …and 2 more", $this->sent[0], 'the most frequent first');
        $this->assertStringContainsString('New errors this hour: 42', $this->sent[0]);
    }

    public function testNothingWhenItIsOff(): void
    {
        $this->send(new MemoryStore(), null, ['general/hourly_status' => '0']);
        $this->assertSame([], $this->sent);
    }
}
