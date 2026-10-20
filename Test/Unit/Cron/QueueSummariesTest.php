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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueueSummariesTest extends TestCase
{
    use Db;
    use Settings;

    private const NOW = 1792483200; // 08:00 UTC

    public function testEachWebsitePastItsSummaryTimeIsQueuedUnlessSentSince(): void
    {
        $config = $this->config(['general/summary' => '1', 'general/summary_time' => '07,30,00']);
        $targets = $this->createStub(Targets::class);
        $targets->method('websites')->willReturn([1 => new Target(1, 1, 'slack', 'C1', 'Shop', 'UTC'),
            2 => new Target(2, 2, 'slack', 'C2', 'Shop', 'America/Los_Angeles')]);
        $queued = [];
        $teamMessage = $this->createStub(TeamMessage::class);
        $teamMessage->method('queue')->willReturnCallback(function ($type, $websiteId, $condition) use (&$queued) {
            $queued[] = [$type, $websiteId, $condition];
            return true;
        });
        $this->assertSame([1], (new QueueSummaries($targets, $config, new SummarySchedule($config), $teamMessage,
            $this->resource($this->db()), new Clock(self::NOW), $this->createStub(LoggerInterface::class)))->execute(),
            'in Los Angeles it is 01:00: yesterday 07:30 was over 12 h ago');
        $this->assertSame([['app_health_summary', 1, "(sent_at IS NULL OR sent_at < '2026-10-20 07:30:00')"]], $queued);
    }
}
