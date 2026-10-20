<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Status;

use CommerceKings\AppHealth\Api\Data\StatusReport;
use CommerceKings\AppHealth\Model\Status\DefaultPolicy;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use PHPUnit\Framework\TestCase;

/** Posted when something is not OK; "All checks OK" once a day, at or after the summary time; else quiet. */
class DefaultPolicyTest extends TestCase
{
    use Settings;

    private function policy(): DefaultPolicy
    {
        return new DefaultPolicy($this->config(['general/summary_time' => '08,00,00',
            'general/hourly_status_minutes' => '90']));
    }

    /** At $time (Europe/Belgrade), with the last "All checks OK" post at $lastOk. */
    private function report(string $time, ?string $lastOk, array $problems = [], array $warnings = [],
        int $errors = 0): StatusReport
    {
        $zone = new \DateTimeZone('Europe/Belgrade');
        return new StatusReport(0, new \DateTimeImmutable($time, $zone), $problems, $warnings, $errors, [],
            null, $lastOk === null ? null : (new \DateTimeImmutable($lastOk, $zone))->getTimestamp());
    }

    public function testSomethingNotOkIsPosted(): void
    {
        $policy = $this->policy();
        $this->assertTrue($policy->shouldPost($this->report('2026-10-20 10:00', '2026-10-20 08:00', ['Cron'])));
        $this->assertTrue($policy->shouldPost($this->report('2026-10-20 10:00', '2026-10-20 08:00', [],
            ['Queue' => 'backlog 120'])));
        $this->assertTrue($policy->shouldPost($this->report('2026-10-20 10:00', '2026-10-20 08:00', [], [], 3)));
    }

    public function testAllOkOnceADayAtOrAfterTheSummaryTime(): void
    {
        $policy = $this->policy();
        $this->assertTrue($policy->shouldPost($this->report('2026-10-20 08:05', '2026-10-19 08:02')),
            'the first after today\'s 08:00');
        $this->assertFalse($policy->shouldPost($this->report('2026-10-20 09:05', '2026-10-20 08:05')),
            'posted today already: quiet');
        $this->assertFalse($policy->shouldPost($this->report('2026-10-20 07:05', '2026-10-19 08:02')),
            'before today\'s time: yesterday\'s was posted');
        $this->assertTrue($policy->shouldPost($this->report('2026-10-20 07:05', '2026-10-18 08:02')),
            'yesterday\'s missed (e.g. something was not OK then): now');
        $this->assertTrue($policy->shouldPost($this->report('2026-10-20 03:00', null)), 'never posted: now');
    }

    public function testTheIntervalIsTheSetting(): void
    {
        $this->assertSame(90, $this->policy()->getIntervalMinutes(0));
    }
}
