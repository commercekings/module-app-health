<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

/**
 * When a website's daily summary is due: from its summary time in the website's time zone until LATE_HOURS after
 * it, once - a run that missed the window (cron down all day) skips that day.
 */
class SummarySchedule
{
    public const LATE_HOURS = 12;

    public function __construct(
        private readonly Config $config
    ) {
    }

    /** The latest summary time at or before $now, as a unix time. */
    public function latestSlot(Target $target, int $now): int
    {
        try {
            $zone = new \DateTimeZone($target->timezone !== '' ? $target->timezone : 'UTC');
        } catch (\Exception) {
            $zone = new \DateTimeZone('UTC');
        }
        $minute = $this->config->getSummaryMinute($target->websiteId);
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
        $slot = $local->setTime(intdiv($minute, 60), $minute % 60);
        if ($slot->getTimestamp() > $now) {
            $slot = $local->modify('-1 day')->setTime(intdiv($minute, 60), $minute % 60);
        }
        return $slot->getTimestamp();
    }

    /** @param string|null $sentAt the delivery state's sent_at (UTC) */
    public function isDue(int $slot, ?string $sentAt, int $now): bool
    {
        return $now < $slot + self::LATE_HOURS * 3600
            && ($sentAt === null || (int)strtotime($sentAt . ' UTC') < $slot);
    }
}
