<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Notification;

use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\Notification\Status;
use CommerceKings\AppHealth\Model\Notification\TeamMessage;
use CommerceKings\AppHealth\Model\Status\DefaultPolicy;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Renderers;
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
    /** @var bool[] whether each was posted */
    private array $posted = [];
    private ?\CommerceKings\AppHealth\Api\StatusPolicyInterface $policy = null;
    private bool $failing = false;
    private int $failedSends = 0;

    private function send(MemoryStore $store, ?string $sentAt = null, array $settings = []): void
    {
        $target = new Target(0, 1, 'slack', 'C0', 'Shop', 'UTC');
        $teamMessage = $this->createStub(TeamMessage::class);
        $teamMessage->method('isFailing')->willReturnCallback(fn () => $this->failing);
        $teamMessage->method('deliver')->willReturnCallback(function ($type, $reference, \Closure $compose) use (
            $target,
            $sentAt
        ): bool {
            $this->assertSame([Status::CODE, '0'], [$type, $reference]);
            $composed = $compose($target, ['sent_at' => $sentAt, 'failed_sends' => (string)$this->failedSends]);
            if ($composed === null) {
                return false;
            }
            $this->sent[] = $composed[0];
            $this->posted[] = $composed[2] ?? true;
            ($composed[1])();
            return true;
        });
        $pool = $this->pool(['cron' => 'Cron', 'queues' => 'Queue', 'caches' => 'Caches'],
            ['email_disabled' => 'Email sending']);
        (new Status($teamMessage, $store, new Messages($pool, new Clock(self::NOW)), $pool, $this->config($settings),
            new Clock(self::NOW), $this->policy ?? new DefaultPolicy($this->config($settings)), Renderers::pool()))
            ->process('0');
    }

    public function testAllChecksOkAndNoErrors(): void
    {
        $this->send((new MemoryStore())->seed(['checks' => [0 => ['cron' => ['status' => 'ok']],
            1 => ['email_disabled' => ['status' => 'ok']]]]));
        $this->assertSame(["*Hourly status · Shop*\n\nAll checks OK"], $this->sent);
    }

    public function testTheProblemsOfEveryScopeAndTheErrorsSinceTheLastStatus(): void
    {
        $store = (new MemoryStore())->seed([
            'checks' => [0 => ['cron' => ['status' => 'fail'], 'queues' => ['status' => 'warn',
                'detail' => 'commercekings.notification.send: 120 messages'],
                'caches' => ['status' => 'ok']], 2 => ['email_disabled' => ['status' => 'fail']]],
            Fingerprints::KEY => ['aaaaaaaa' => ['label' => 'Unable to send'],
                'bbbbbbbb' => ['label' => 'Magento\\Framework\\Exception\\NoSuchEntityException']],
            Fingerprints::ERRORS_KEY => [[self::NOW - 4000, 'aaaaaaaa', 9, 1], // before the last status
                [self::NOW - 3000, 'aaaaaaaa', 2, 0], [self::NOW - 600, 'aaaaaaaa', 1, 0],
                [self::NOW - 300, 'bbbbbbbb', 5, 1]],
        ]);
        $this->send($store, gmdate('Y-m-d H:i:s', self::NOW - 3600));
        $this->assertSame(["*Hourly status · Shop*\n\n2 problems: Cron, Email sending\n"
            . "Warnings: Queue (commercekings.notification.send: 120 messages)\n\n"
            . "New errors this hour: 5\n• NoSuchEntityException (5×, new)"], $this->sent,
            'an error seen before only from ERROR_THRESHOLD in the interval: Unable to send (3×) is not news');
    }

    public function testOnlyWarnings(): void
    {
        $this->send((new MemoryStore())->seed(['checks' => [0 => ['queues' => ['status' => 'warn']]]]));
        $this->assertSame(["*Hourly status · Shop*\n\nWarnings: Queue"], $this->sent);
    }

    public function testAtMostFiveErrorsThenCounted(): void
    {
        $errors = [];
        foreach (range(1, 7) as $i) {
            $errors[] = [self::NOW - 60, 'fp' . $i, 20 - $i, 0];
        }
        $this->send((new MemoryStore())->seed([Fingerprints::ERRORS_KEY => $errors]));
        $this->assertStringEndsWith("• #fp5 (15×)\n• …and 2 more", $this->sent[0], 'the most frequent first');
        $this->assertStringContainsString('New errors this hour: 112', $this->sent[0]);
    }

    public function testSentToTheScopeItWasQueuedFor(): void
    {
        $teamMessage = $this->createStub(TeamMessage::class);
        $teamMessage->method('deliver')->willReturnCallback(function ($type, $reference) {
            $this->sent[] = $reference;
            return true;
        });
        $pool = $this->pool([]);
        (new Status($teamMessage, new MemoryStore(), new Messages($pool, new Clock(self::NOW)), $pool, $this->config(),
            new Clock(self::NOW), new DefaultPolicy($this->config()), Renderers::pool()))->process('2');
        $this->assertSame(['2'], $this->sent, 'shop-wide alerts off: the first website\'s recipient');
    }

    public function testTheIntervalIsFifteenMinutesToThreeHours(): void
    {
        $this->assertSame([60, 15, 180, 90], array_map(fn ($minutes) => $this->config($minutes === null ? []
            : ['general/hourly_status_minutes' => $minutes])->getHourlyStatusMinutes(), [null, '5', '600', '90']),
            'at most 3 hours: what errors:recent keeps');
    }

    public function testOnlyWhatTheLastPostedStatusDidNotSay(): void
    {
        $store = (new MemoryStore())->seed(['checks' => [0 => ['cron' => ['status' => 'fail'],
            'queues' => ['status' => 'ok']]]]);
        $this->send($store);
        $this->assertSame(["*Hourly status · Shop*\n\n1 problem: Cron"], $this->sent);
        $this->assertSame(['cron'], $store->row(Status::POSTED_KEY)[0]['open']);
        $this->sent = [];
        $this->posted = [];
        $this->send($store);
        $this->assertSame([false], $this->posted, 'Cron still failing: told already, nothing new - quiet');
        $store->seed(['checks' => [0 => ['cron' => ['status' => 'fail'], 'queues' => ['status' => 'warn',
            'detail' => 'growing']]]]);
        $this->send($store);
        $this->assertSame([false, true], $this->posted);
        $this->assertSame("*Hourly status · Shop*\n\nWarnings: Queue (growing)\nStill open: Cron", $this->sent[1],
            'the new warning, and what is still open');
        $this->assertSame(['cron', 'queues'], $store->row(Status::POSTED_KEY)[0]['open']);
    }

    public function testAWarningThatClearedQuietlyIsNewWhenItComesBack(): void
    {
        $warn = ['status' => 'warn', 'detail' => 'growing'];
        $store = (new MemoryStore())->seed(['checks' => [0 => ['cron' => ['status' => 'fail'], 'queues' => $warn]]]);
        $this->send($store); // posted: Cron and Queue open
        $store->seed(['checks' => [0 => ['cron' => ['status' => 'fail'], 'queues' => ['status' => 'ok']]]]);
        $this->send($store);
        $this->assertSame([true, false], $this->posted, 'Queue OK again, Cron told: quiet');
        $this->assertSame(['cron'], $store->row(Status::POSTED_KEY)[0]['open'], 'the quiet status still forgets'
            . ' what is no longer open');
        $store->seed(['checks' => [0 => ['cron' => ['status' => 'fail'], 'queues' => $warn]]]);
        $this->send($store);
        $this->assertSame([true, false, true], $this->posted, 'the warning back: new, posted');
        $this->assertStringStartsWith("*Hourly status · Shop*\n\nWarnings: Queue (growing)", $this->sent[2]);
    }

    public function testAKnownErrorIsListedAgainFromTheThresholdANewOneAtOnce(): void
    {
        $store = (new MemoryStore())->seed([Fingerprints::KEY => ['aaaaaaaa' => ['label' => 'Unable to send'],
            'bbbbbbbb' => ['label' => 'Slow']],
            Fingerprints::ERRORS_KEY => [[self::NOW - 60, 'aaaaaaaa', Status::ERROR_THRESHOLD, 0],
                [self::NOW - 60, 'bbbbbbbb', Status::ERROR_THRESHOLD - 1, 0]]]);
        $this->send($store);
        $this->assertStringEndsWith("New errors this hour: 10\n• Unable to send (10×)", $this->sent[0],
            'Slow (9×, seen before) is in the daily summary, not here');
    }

    public function testAfterAFailedSendTheStatusIsPostedNotDeliveredQuietly(): void
    {
        $store = (new MemoryStore())->seed(['checks' => [0 => ['cron' => ['status' => 'ok']]]]);
        $this->send($store);
        $this->send($store);
        $this->assertSame([true, false], $this->posted, 'all OK posted today already: quiet');
        $this->failing = true; // e.g. an alert failing into retry: the token revoked
        $this->send($store);
        $this->failing = false;
        $this->failedSends = 2; // or the status's own sends
        $this->send($store);
        $this->assertSame([true, false, true, true], $this->posted,
            'while a message fails, the status goes through the channel: only a real post proves it works again');
    }

    public function testAllOkIsPostedOnceADayElseDeliveredQuietly(): void
    {
        $store = (new MemoryStore())->seed(['checks' => [0 => ['cron' => ['status' => 'ok']]]]);
        $this->send($store);
        $this->assertSame([true], $this->posted, 'never posted as all OK: posted');
        $this->assertSame(['at' => self::NOW, 'open' => [], 'ok_at' => self::NOW], $store->row(Status::POSTED_KEY)[0]);
        $this->send($store);
        $this->assertSame([true, false], $this->posted, 'again today: delivered quietly');
        $this->assertSame(['at' => self::NOW, 'open' => [], 'ok_at' => self::NOW], $store->row(Status::POSTED_KEY)[0],
            'a quiet one changes nothing');
        $store->seed(['checks' => [0 => ['cron' => ['status' => 'fail']]]]);
        $this->send($store);
        $this->assertSame([true, false, true], $this->posted, 'a problem: posted');
    }

    public function testAnotherModulesPolicyDecides(): void
    {
        $this->policy = $policy = new class implements \CommerceKings\AppHealth\Api\StatusPolicyInterface {
            public ?\CommerceKings\AppHealth\Api\Data\StatusReport $seen = null;

            public function getIntervalMinutes(int $scopeId): int
            {
                return 60;
            }

            public function shouldPost(\CommerceKings\AppHealth\Api\Data\StatusReport $report): bool
            {
                $this->seen = $report;
                return false; // e.g. outside opening hours
            }
        };
        $this->send((new MemoryStore())->seed(['checks' => [0 => ['cron' => ['status' => 'fail']]],
            Fingerprints::KEY => ['aaaaaaaa' => ['label' => 'Unable to send']],
            Fingerprints::ERRORS_KEY => [[self::NOW - 60, 'aaaaaaaa', 2, 1]]]));
        $this->assertSame([false], $this->posted, 'quiet, though Cron fails: its own alert went at once');
        $report = $policy->seen;
        $this->assertNotNull($report);
        $this->assertSame([0, ['Cron'], 2, [['label' => 'Unable to send', 'count' => 2, 'new' => true]], 'UTC'],
            [$report->scopeId, $report->problems, $report->errorCount, $report->errors,
                $report->now->getTimezone()->getName()]);
    }

    public function testTheIntervalStaysWithinFifteenMinutesAndThreeHours(): void
    {
        $policy = $this->createStub(\CommerceKings\AppHealth\Api\StatusPolicyInterface::class);
        $policy->method('getIntervalMinutes')->willReturnOnConsecutiveCalls(5, 600, 45);
        $this->assertSame([15, 180, 45], [Status::intervalMinutes($policy, 0), Status::intervalMinutes($policy, 0),
            Status::intervalMinutes($policy, 0)]);
    }

    public function testUrgentAlertsNeverAskThePolicy(): void
    {
        $parameters = (new \ReflectionMethod(\CommerceKings\AppHealth\Model\Notification\Alert::class,
            '__construct'))->getParameters();
        $this->assertSame([], array_filter($parameters, fn ($p) => (string)$p->getType()
            === \CommerceKings\AppHealth\Api\StatusPolicyInterface::class), 'the alert type has no policy');
    }

    public function testNotAgainSoonAfterOneWasSent(): void
    {
        $store = (new MemoryStore())->seed(['checks' => [0 => ['cron' => ['status' => 'fail']]]]);
        $this->send($store, gmdate('Y-m-d H:i:s', self::NOW - 600));
        $this->assertSame([], $this->sent, 'a late copy of the queue message 10 min after a status: not due');
        $this->send($store, gmdate('Y-m-d H:i:s', self::NOW - 3600));
        $this->assertCount(1, $this->sent, 'an interval later: due');
    }

    public function testNothingWhenItIsOff(): void
    {
        $this->send(new MemoryStore(), null, ['general/hourly_status' => '0']);
        $this->assertSame([], $this->sent);
    }
}
