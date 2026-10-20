<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\AppHealth\Api\Data\CheckResult;

/**
 * What a website's team should be told now, comparing each check's last result with what it was last told
 * ("alerted:<website>"): a status other than the one told (a problem, or "recovered" back to ok), or a fail told
 * longer ago than the repeat interval. A check never told counts as told ok, so an ok check is never news.
 */
class AlertPlan
{
    /** One-off news per scope: {"next": id, "items": [[id, time, check code, text], ...]}. */
    public const NOTICES = 'notices:';
    /** The last notice told per check code: {"<code>": id}. */
    public const NOTICED = 'noticed:';
    public const NOTICES_KEPT = 30;
    public const NOTICES_HOURS = 24;

    public const PROBLEM = 'problem';
    public const RECOVERED = 'recovered';
    public const REPEAT = 'repeat';

    /**
     * @param array<string, array<mixed>> $checks code => ['status' => ..., ...] (the "checks" row)
     * @param array<string, array<mixed>> $alerted code => ['status' => ..., 'at' => unix time]
     * @return array<string, string> code => self::PROBLEM, RECOVERED or REPEAT, in the checks' order
     */
    public static function changes(array $checks, array $alerted, int $now, int $repeatSeconds): array
    {
        $changes = [];
        foreach ($checks as $code => $check) {
            $status = (string)($check['status'] ?? CheckResult::OK);
            $told = (string)($alerted[$code]['status'] ?? CheckResult::OK);
            if ($status !== $told) {
                $changes[$code] = $status === CheckResult::OK ? self::RECOVERED : self::PROBLEM;
            } elseif ($status === CheckResult::FAIL && (int)($alerted[$code]['at'] ?? 0) <= $now - $repeatSeconds) {
                $changes[$code] = self::REPEAT;
            }
        }
        return $changes;
    }

    /**
     * What was told after a message with $changes went out; checks no longer run are forgotten.
     *
     * @param array<string, array<mixed>> $checks
     * @param array<string, array<mixed>> $alerted
     * @param array<string, string> $changes
     * @return array<string, array<mixed>>
     */
    public static function told(array $checks, array $alerted, array $changes, int $now): array
    {
        $alerted = array_intersect_key($alerted, $checks);
        foreach (array_keys($changes) as $code) {
            $status = (string)($checks[$code]['status'] ?? CheckResult::OK);
            if ($status === CheckResult::OK) {
                unset($alerted[$code]);
            } else {
                $alerted[$code] = ['status' => $status, 'at' => $now];
            }
        }
        return $alerted;
    }

    /**
     * The notices row with this run's added, the oldest dropped beyond NOTICES_KEPT or NOTICES_HOURS.
     *
     * @param array<mixed> $row
     * @param array<string, string[]> $byCode
     * @return array<mixed>
     */
    public static function addNotices(array $row, array $byCode, int $now): array
    {
        $next = (int)($row['next'] ?? 1);
        $items = array_values(array_filter((array)($row['items'] ?? []),
            fn ($item) => is_array($item) && (int)($item[1] ?? 0) > $now - self::NOTICES_HOURS * 3600));
        foreach ($byCode as $code => $texts) {
            foreach ($texts as $text) {
                $items[] = [$next++, $now, (string)$code, (string)$text];
            }
        }
        return ['next' => $next, 'items' => array_slice($items, -self::NOTICES_KEPT)];
    }

    /**
     * The notices not told yet, by check code in the order they came.
     *
     * @param array<mixed> $row the notices row
     * @param array<mixed> $noticed the noticed row
     * @return array<string, array<int, string>> code => [id => text]
     */
    public static function pendingNotices(array $row, array $noticed): array
    {
        $pending = [];
        foreach ((array)($row['items'] ?? []) as $item) {
            [$id, , $code, $text] = array_pad((array)$item, 4, '');
            if ((int)$id > (int)($noticed[$code] ?? 0)) {
                $pending[(string)$code][(int)$id] = (string)$text;
            }
        }
        return $pending;
    }
}
