<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The plain-text messages, built only from the shop's name, check labels, statuses, data-free details and counts:
 *
 *   App health - Moj Planer (all websites)
 *   FAIL Cron: no job has run since 14:05
 *   RECOVERED Indexers
 *   OK Caches, Maintenance mode and Exceptions
 */
class Messages
{
    public function __construct(
        private readonly CheckPool $pool,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @param array<string, array<mixed>> $checks the "checks" row
     * @param array<string, string> $changes AlertPlan::changes()
     */
    public function alert(Target $target, array $checks, array $changes): string
    {
        $lines = ['App health - ' . $this->title($target)];
        $recovered = [];
        $ok = [];
        foreach ($checks as $code => $check) {
            $status = (string)($check['status'] ?? CheckResult::OK);
            if ($status !== CheckResult::OK) {
                $lines[] = $this->problem($target, (string)$code, $check);
            } elseif (($changes[$code] ?? null) === AlertPlan::RECOVERED) {
                $recovered[] = 'RECOVERED ' . $this->pool->getLabel((string)$code);
            } else {
                $ok[] = $this->pool->getLabel((string)$code);
            }
        }
        $lines = array_merge($lines, $recovered);
        if ($ok !== []) {
            $lines[] = 'OK ' . Format::list($ok);
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string, array<mixed>> $checks the "checks" row
     * @param array<int|string, array<mixed>> $tallies the day's hours: code => ['w' => worst rank, 'c' => counts]
     */
    public function summary(Target $target, array $checks, array $tallies, int $now): string
    {
        $lines = ['Daily summary - ' . $target->name . ' - ' . $this->local($target, $now, 'j M')];
        $worst = [];
        $counts = [];
        foreach ($tallies as $tally) {
            foreach ($tally as $code => $hour) {
                $worst[$code] = max($worst[$code] ?? 0, (int)($hour['w'] ?? 0));
                foreach ((array)($hour['c'] ?? []) as $key => $count) {
                    $counts[$code][$key] = ($counts[$code][$key] ?? 0) + (int)$count;
                }
            }
        }
        $countLines = [];
        $hadProblems = [];
        $okAllDay = [];
        foreach ($checks as $code => $check) {
            $label = $this->pool->getLabel((string)$code);
            $status = (string)($check['status'] ?? CheckResult::OK);
            if ($status !== CheckResult::OK) {
                $lines[] = $this->problem($target, (string)$code, $check);
            } elseif (($worst[$code] ?? 0) > 0) {
                $hadProblems[] = $label . ' (' . CheckResult::fromRank($worst[$code]) . ')';
            } else {
                $okAllDay[] = $label;
            }
            $shown = $this->nonZero($counts[$code] ?? []);
            if ($shown !== []) {
                $countLines[] = $label . ': ' . Format::counts($shown);
            }
        }
        $lines = array_merge($lines, $countLines);
        if ($hadProblems !== []) {
            $lines[] = 'Had problems: ' . Format::list($hadProblems);
        }
        if ($okAllDay !== []) {
            $lines[] = 'OK all day: ' . Format::list($okAllDay);
        }
        return implode("\n", $lines);
    }

    public function test(Target $target): string
    {
        return 'App health - ' . $this->title($target) . "\n" . ($target->isShopWide()
            ? 'Test alert: this channel gets the alerts of the checks for all websites (cron, indexers, caches, ...).'
            : 'Test alert: this channel gets this website\'s alerts (e.g. its email settings) and its daily summary.');
    }

    /** The shop's name; the shop-wide checks' messages say they cover all websites. */
    private function title(Target $target): string
    {
        return $target->name . ($target->isShopWide() ? ' (all websites)' : '');
    }

    /**
     * "FAIL Cron: no job has run since 14:05"; a detail without a time of its own says when the problem was first
     * seen.
     *
     * @param array<mixed> $check
     */
    private function problem(Target $target, string $code, array $check): string
    {
        $detail = (string)($check['detail'] ?? '');
        $since = (int)($check['since'] ?? 0);
        $timed = preg_match('/\{t:\d+\}/', $detail) === 1;
        $detail = (string)preg_replace_callback('/\{t:(\d+)\}/',
            fn (array $match) => $this->when($target, (int)$match[1]), $detail);
        return strtoupper((string)$check['status']) . ' ' . $this->pool->getLabel($code)
            . ($detail !== '' ? ': ' . $detail : '')
            . ($since > 0 && !$timed ? ' (first seen ' . $this->when($target, $since) . ')' : '');
    }

    /** "14:05" today in the recipient's time zone, else "19 Oct 14:05". */
    private function when(Target $target, int $time): string
    {
        $today = $this->local($target, $this->dateTime->gmtTimestamp(), 'Y-m-d')
            === $this->local($target, $time, 'Y-m-d');
        return $this->local($target, $time, $today ? 'H:i' : 'j M H:i');
    }

    /**
     * Totals above 0 and their items.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function nonZero(array $counts): array
    {
        return array_filter($counts, function (int $count, string $key) use ($counts): bool {
            $total = explode(':', $key, 2)[0];
            return $count > 0 && ($counts[$total] ?? 1) > 0;
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function local(Target $target, int $time, string $format): string
    {
        try {
            $zone = new \DateTimeZone($target->timezone !== '' ? $target->timezone : 'UTC');
        } catch (\Exception) {
            $zone = new \DateTimeZone('UTC');
        }
        return (new \DateTimeImmutable('@' . $time))->setTimezone($zone)->format($format);
    }
}
