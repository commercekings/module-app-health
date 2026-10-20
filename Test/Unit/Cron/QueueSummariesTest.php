<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Cron;

use CommerceKings\AppHealth\Cron\QueueSummaries;
use CommerceKings\AppHealth\Model\Notification\TeamMessage;
use CommerceKings\AppHealth\Model\SummarySchedule;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Model\Targets;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Db;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueueSummariesTest extends TestCase
{
    use \CommerceKings\AppHealth\Test\Unit\Stub\Domain;

    use Db;
    use Settings;

    private const NOW = 1792483200; // 08:00 UTC

    /** @var array<int, array<string, mixed>> delivery state by website */
    private array $rows = [];

    public function testTheGlobalSummaryAndThoseOfWebsiteChannelsPastTheirTime(): void
    {
        $config = $this->config(['general/summary' => '1', 'general/summary_time' => '07,30,00']);
        $targets = $this->createStub(Targets::class);
        $targets->method('shopWide')->willReturn(new Target(0, 1, 'slack', 'C0', 'Shop', 'UTC'));
        $targets->method('websites')->willReturn([1 => new Target(1, 1, 'slack', 'C0', 'Shop', 'UTC', false),
            2 => new Target(2, 2, 'slack', 'C2', 'Shop US', 'America/Los_Angeles'),
            3 => new Target(3, 3, 'slack', 'C3', 'Shop EU', 'UTC')]);
        $queued = [];
        $teamMessage = $this->createStub(TeamMessage::class);
        $teamMessage->method('queue')->willReturnCallback(function ($type, $websiteId, $condition) use (&$queued) {
            $queued[] = [$type, $websiteId, $condition];
            return true;
        });
        $state = $this->createStub(DeliveryStateInterface::class);
        $state->method('load')->willReturnCallback(fn ($type, $id) => $this->rows[$id] ?? null);
        $cron = new QueueSummaries($targets, $config, new SummarySchedule($config), $teamMessage,
            $this->resource($this->db()), new Clock(self::NOW), $this->createStub(LoggerInterface::class), $state,
            $this->siteDomain());
        $this->assertSame([0, 3], $cron->execute(), 'website 1 (no channel of its own) is in the global one; in Los'
            . ' Angeles it is 01:00: yesterday 07:30 was over 12 h ago');
        $this->assertSame([['app_health_summary', 0, "(sent_at IS NULL OR sent_at < '2026-10-20 07:30:00')"],
            ['app_health_summary', 3, "(sent_at IS NULL OR sent_at < '2026-10-20 07:30:00')"]], $queued);
        $queued = [];
        $this->rows[0] = ['queued_at' => null, 'sent_at' => '2026-10-20 07:31:00'];
        $this->rows[3] = ['queued_at' => '2026-10-20 07:59:00', 'sent_at' => null];
        $this->assertSame([], $cron->execute(), 'sent since 07:30, or on its way: one read each, nothing queued');
        $this->assertSame([], $queued);
    }

    public function testACopyOfTheShopQueuesNoSummary(): void
    {
        $targets = $this->createStub(Targets::class);
        $targets->method('shopWide')->willReturn(new Target(0, 1, 'slack', 'C0', 'Shop', 'UTC'));
        $config = $this->config(['general/summary' => '1', 'general/summary_time' => '07,30,00']);
        $teamMessage = $this->createStub(TeamMessage::class);
        $teamMessage->method('queue')->willReturnCallback(fn () => $this->fail('queued on a copy'));
        $cron = new QueueSummaries($targets, $config, new SummarySchedule($config), $teamMessage,
            $this->resource($this->db()), new Clock(self::NOW), $this->createStub(LoggerInterface::class),
            $this->createStub(DeliveryStateInterface::class), $this->siteDomain(true));
        $this->assertSame([], $cron->execute());
    }

    public function testNothingWhileOffOrWithoutTheSummary(): void
    {
        $targets = $this->createStub(Targets::class);
        $targets->method('shopWide')->willReturn(new Target(0, 1, 'slack', 'C0', 'Shop', 'UTC'));
        $config = $this->config(['general/summary' => '0']);
        $cron = new QueueSummaries($targets, $config, new SummarySchedule($config),
            $this->createStub(TeamMessage::class), $this->resource($this->db()), new Clock(self::NOW),
            $this->createStub(LoggerInterface::class), $this->createStub(DeliveryStateInterface::class),
            $this->siteDomain());
        $this->assertSame([], $cron->execute());
    }
}
