<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\SummarySchedule;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use PHPUnit\Framework\TestCase;

class SummaryScheduleTest extends TestCase
{
    use Settings;

    private const NOW = 1792483200; // 2026-10-20 08:00 UTC

    private function slot(string $time, string $zone, int $now = self::NOW): int
    {
        $schedule = new SummarySchedule($this->config(['general/summary_time' => $time]));
        return $schedule->latestSlot(new Target(1, 1, 'slack', 'C1', 'Shop', $zone), $now);
    }

    public function testTheLatestSummaryTimeInTheWebsitesZone(): void
    {
        $this->assertSame('2026-10-20 06:00', gmdate('Y-m-d H:i', $this->slot('08,00,00', 'Europe/Belgrade')),
            '08:00 in Belgrade (UTC+2) has passed');
        $this->assertSame('2026-10-19 09:00', gmdate('Y-m-d H:i', $this->slot('11,00,00', 'Europe/Belgrade')),
            'not yet today: yesterday\'s');
        $this->assertSame('2026-10-20 08:00', gmdate('Y-m-d H:i', $this->slot('08,00,00', 'UTC')), 'exactly now');
        $this->assertSame('2026-10-20 00:00', gmdate('Y-m-d H:i', $this->slot('', 'Not/AZone')), 'midnight, UTC');
    }

    public function testDueOnceWithinTheWindow(): void
    {
        $schedule = new SummarySchedule($this->config());
        $slot = self::NOW - 3600;
        $this->assertTrue($schedule->isDue($slot, null, self::NOW));
        $this->assertTrue($schedule->isDue($slot, gmdate('Y-m-d H:i:s', $slot - 86400), self::NOW), 'sent yesterday');
        $this->assertFalse($schedule->isDue($slot, gmdate('Y-m-d H:i:s', $slot + 60), self::NOW), 'sent today');
        $this->assertFalse($schedule->isDue($slot, null, $slot + SummarySchedule::LATE_HOURS * 3600),
            'too late: the day is skipped');
    }
}
