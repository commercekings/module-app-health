<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Notification;

use CommerceKings\AppHealth\Model\Notification\TeamMessage;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Model\Targets;
use CommerceKings\AppHealth\Test\Unit\Stub\Db;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use Magento\Framework\DB\Sql\Expression;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TeamMessageTest extends TestCase
{
    use Db;

    private const ROW = ['type' => 'app_health_alert', 'reference_id' => '1', 'subject_key' => '',
        'queued_at' => '2026-10-20 08:00:00', 'sent_at' => null, 'skip_until' => null, 'failed_sends' => '0',
        'delivery_version' => '4'];

    /** @var string[] */
    private array $logs = [];
    /** @var Delivery[] */
    private array $sent = [];
    /** @var string[] */
    private array $published = [];
    private int $recorded = 0;

    /**
     * @param array<string, mixed>|null $row the delivery state row
     * @param \Throwable|string $send what the sender answers
     * @param int[] $updates what update() answers, in order
     */
    private function teamMessage(?array $row, \Throwable|string $send = SenderInterface::SENT, ?Target $target = null,
        array $updates = [], ?\Throwable $publish = null,
        ?\CommerceKings\AppHealth\Model\SiteDomain $domain = null): TeamMessage
    {
        $state = $this->createStub(DeliveryStateInterface::class);
        $state->method('getTableName')->willReturn('ck_notification_delivery_state');
        $state->method('keyCondition')->willReturnCallback(fn ($type, $id) => ['type = ?' => $type,
            'reference_id = ?' => $id, 'subject_key = ?' => '']);
        $state->method('load')->willReturn($row);
        $queue = $this->createStub(QueueInterface::class);
        $queue->method('publish')->willReturnCallback(function ($type, $reference) use ($publish) {
            if ($publish) {
                throw $publish;
            }
            $this->published[] = $type . '|' . $reference;
        });
        $sender = $this->createStub(SenderInterface::class);
        $sender->method('send')->willReturnCallback(function (Delivery $delivery) use ($send) {
            $this->sent[] = $delivery;
            if ($send instanceof \Throwable) {
                throw $send;
            }
            return $send;
        });
        $targets = $this->createStub(Targets::class);
        $targets->method('forWebsite')->willReturn($target === null
            ? new Target(1, 3, 'slack', 'C0123456789', 'Moj Planer', 'UTC')
            : ($target->websiteId < 0 ? null : $target));
        $logger = $this->createStub(LoggerInterface::class);
        foreach (['error', 'warning', 'info'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message) use ($level) {
                $this->logs[] = $level . ': ' . $message;
            });
        }
        return new TeamMessage($this->resource($this->db(['update' => $updates])), $state, $queue, $sender, $targets,
            $logger, $domain);
    }

    private function compose(): \Closure
    {
        return fn (Target $target, array $row) => ['FAIL Cron for ' . $target->name, function () {
            $this->recorded++;
            $this->transactions[] = ['recorded', count($this->updates)];
        }];
    }

    /** @return list<array<string, mixed>> the data of the update() calls, in order */
    private function updateData(): array
    {
        return array_values(array_map(fn ($update) => array_map(fn ($value) => $value instanceof Expression
            ? (string)$value : $value, $update[1]), $this->updates));
    }

    public function testAQuietDeliveryIsRecordedAsSentWithoutTheChannel(): void
    {
        $this->assertTrue($this->teamMessage(self::ROW)->deliver('app_health_status', '0', fn () => ['All checks OK',
            function () {
                $this->recorded++;
            }, false]));
        $this->assertSame([], $this->sent, 'the provider is not called');
        $this->assertSame(1, $this->recorded);
        [$claim, $sent] = $this->updateData();
        $this->assertSame(['delivery_version' => 5], $claim, 'claimed like any message: the queue and consumer worked');
        $this->assertSame('NOW()', $sent['sent_at'], 'sent_at: the heartbeat\'s proof');
        $this->assertStringContainsString('info: CommerceKings_AppHealth: app_health_status for scope 0 delivered'
            . ' quietly', implode("\n", $this->logs));
    }

    public function testACopyDropsWhatItsQueueHolds(): void
    {
        $copy = $this->createStub(\CommerceKings\AppHealth\Model\SiteDomain::class);
        $copy->method('matches')->willReturn(false);
        $copy->method('stored')->willReturn('shop.example.com');
        $copy->method('current')->willReturn('staging.example.com');
        $this->assertFalse($this->teamMessage(self::ROW, SenderInterface::SENT, null, [], null, $copy)
            ->deliver('app_health_alert', '1', $this->compose()));
        $this->assertSame([[], 0], [$this->sent, $this->recorded], 'nothing sent from a copy, nothing recorded');
        $this->assertSame([['queued_at' => null]], $this->updateData(), 'its mark cleared');
        $this->assertStringContainsString('dropped: this looks like a copy of shop.example.com',
            implode("\n", $this->logs));
    }

    public function testQueueMarksFreeStateInOneStatementThenPublishes(): void
    {
        $this->assertTrue($this->teamMessage(null)->queue('app_health_alert', 1));
        $this->assertSame(['app_health_alert|1'], $this->published);
        [, $data, $where] = $this->updates[0];
        $this->assertSame('NOW()', (string)$data['queued_at']);
        $this->assertContains('(queued_at IS NULL OR queued_at < NOW() - INTERVAL 60 MINUTE)', $where);
        $this->assertContains('(skip_until IS NULL OR skip_until <= NOW())', $where, 'a retry waits its turn');
    }

    public function testQueueAlreadyMarkedPublishesNothing(): void
    {
        $this->assertFalse($this->teamMessage(null, updates: [0])->queue('app_health_alert', 1));
        $this->assertSame([], $this->published);
    }

    public function testAFailedPublishClearsTheMark(): void
    {
        try {
            $this->teamMessage(null, publish: new \RuntimeException('broker down'))->queue('app_health_alert', 1);
            $this->fail('rethrown');
        } catch (\RuntimeException) {
            $this->assertSame([null], array_column($this->updateData(), 'queued_at') === [] ? [null]
                : [end($this->updates)[1]['queued_at']]);
        }
    }

    public function testDeliverSendsAServiceMessageOnceAndRecordsIt(): void
    {
        $this->teamMessage(self::ROW)->deliver('app_health_alert', '1', $this->compose());
        $this->assertCount(1, $this->sent);
        $delivery = $this->sent[0];
        $this->assertSame([3, 'slack', 'C0123456789', false, false, 'app_health_alert'], [$delivery->storeId,
            $delivery->channel, $delivery->recipient, $delivery->useCooldown, $delivery->stoppable, $delivery->type]);
        $this->assertSame('FAIL Cron for Moj Planer', ($delivery->content)()->text);
        $this->assertSame(1, $this->recorded);
        [$claim, $sent] = $this->updateData();
        $this->assertSame(['delivery_version' => 5], $claim);
        $this->assertContains('2026-10-20 08:00:00', $this->updates[0][2], 'the mark this message was queued with');
        $this->assertSame(['sent_at' => 'NOW()', 'queued_at' => null, 'failed_sends' => 0, 'skip_until' => null],
            $sent);
        $this->assertSame(5, $this->updates[1][2]['delivery_version = ?']);
        $this->assertSame([['beginTransaction', 1], ['recorded', 1], ['commit', 2]], $this->transactions,
            'what was told and "sent" in one transaction: no second post after a crash between them');
    }

    public function testAClaimLostToAnotherWorkerSendsNothing(): void
    {
        $this->teamMessage(self::ROW, updates: [0])->deliver('app_health_alert', '1', $this->compose());
        $this->assertSame([], $this->sent);
    }

    public function testNothingToSendOrNoTargetReleasesTheMark(): void
    {
        $this->teamMessage(self::ROW)->deliver('app_health_alert', '1', fn () => null);
        $this->teamMessage(self::ROW, target: new Target(-1, 0, '', '', '', ''))
            ->deliver('app_health_alert', '1', $this->compose());
        $this->assertSame([], $this->sent);
        $this->assertSame([['queued_at' => null], ['queued_at' => null]], $this->updateData());
        $this->assertSame('2026-10-20 08:00:00', $this->updates[0][2]['queued_at = ?'], 'only the mark it read');
    }

    public function testATemporaryRefusalWaitsItsRetryAfterWithoutCountingAFailure(): void
    {
        $this->teamMessage(self::ROW, new TemporaryFailureException('rate limited', 30))
            ->deliver('app_health_alert', '1', $this->compose());
        $this->assertSame(['queued_at' => null, 'skip_until' => 'NOW() + INTERVAL 60 SECOND'], $this->updateData()[1],
            'at least a minute');
        $this->assertSame(0, $this->recorded);
    }

    public function testAnyOtherFailureWaitsLongerEachTimeAndIsRethrown(): void
    {
        $row = ['failed_sends' => '5'] + self::ROW;
        try {
            $this->teamMessage($row, new \RuntimeException('Slack unreachable, token xo' . 'xb-secret'))
                ->deliver('app_health_alert', '1', $this->compose());
            $this->fail('rethrown');
        } catch (\RuntimeException) {
            $this->assertSame(['queued_at' => null, 'failed_sends' => 6, 'skip_until' => 'NOW() + INTERVAL 60 MINUTE'],
                $this->updateData()[1], 'never given up: the longest wait again');
        }
        $this->assertSame(0, $this->recorded);
        $this->assertStringNotContainsString('xoxb', implode("\n", $this->logs), 'only the class is logged');
        $this->assertStringContainsString('RuntimeException', implode("\n", $this->logs));
    }

    public function testAnInvalidRecipientIsASettingNotARejectedMessage(): void
    {
        $this->teamMessage(self::ROW, new \InvalidArgumentException('No valid recipient'))
            ->deliver('app_health_alert', '1', $this->compose());
        $this->assertSame(1, $this->updateData()[1]['failed_sends']);
        $this->assertStringContainsString('not valid for the "slack" channel', implode("\n", $this->logs));
    }

    public function testMessageSentIsNeverSentAgain(): void
    {
        $this->teamMessage(self::ROW, new MessageSentException('sent', 0, new \LogicException('cleanup')))
            ->deliver('app_health_alert', '1', $this->compose());
        $this->assertSame(1, $this->recorded);
        $this->assertSame('NOW()', $this->updateData()[1]['sent_at']);
    }

    public function testNotSentForAnotherReasonClearsTheMark(): void
    {
        $this->teamMessage(self::ROW, SenderInterface::COOLDOWN)->deliver('app_health_alert', '1', $this->compose());
        $this->assertSame(['queued_at' => null], $this->updateData()[1]);
        $this->assertSame(0, $this->recorded);
    }
}
