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
    /** The last notice told per check code, and when: {"<code>": {"id": id, "at": time}}. */
    public const NOTICED = 'noticed:';
    public const NOTICES_KEPT = 30;
    public const NOTICES_HOURS = 24;

    /** Checks that count in a time window: "recovered" only after Config::getRecoverRuns() OK runs in a row. */
    public const WINDOW_CHECKS = ['exceptions', 'system_log', 'queues', 'email_failing'];

    /** Checks whose news goes to the global channel only (security findings), never to a website channel. */
    public const GLOBAL_ONLY = ['sensitive_data'];

    public const PROBLEM = 'problem';
    public const RECOVERED = 'recovered';
    public const REPEAT = 'repeat';

    /**
     * What to send at once: a check going to FAIL (a problem), or back to OK after a told problem (recovered), or a
     * fail still going on after the repeat interval. A WARN is not sent at once (the hourly status and the daily
     * summary list it): ok to warn is no news, and fail to warn waits for the OK. A check in WINDOW_CHECKS (counts in
     * a time window) recovers only after $recoverRuns OK runs in a row; the others (a state) at their first OK run.
     *
     * @param array<string, array<mixed>> $checks code => ['status' => ..., ...] (the "checks" row)
     * @param array<string, array<mixed>> $alerted code => ['status' => ..., 'at' => unix time]
     * @param int $recoverRuns ok runs in a row ("runs" in the checks row) before a window check counts as recovered
     * @return array<string, string> code => self::PROBLEM, RECOVERED or REPEAT, in the checks' order
     */
    public static function changes(array $checks, array $alerted, int $now, int $repeatSeconds,
        int $recoverRuns = 1): array
    {
        $changes = [];
        foreach ($checks as $code => $check) {
            $status = (string)($check['status'] ?? CheckResult::OK);
            $told = (string)($alerted[$code]['status'] ?? CheckResult::OK);
            if ($status === CheckResult::WARN || $status === $told) {
                if ($status === CheckResult::FAIL && (int)($alerted[$code]['at'] ?? 0) <= $now - $repeatSeconds) {
                    $changes[$code] = self::REPEAT;
                }
                continue;
            }
            if ($status === CheckResult::OK && in_array($code, self::WINDOW_CHECKS, true)
                && (int)($check['runs'] ?? $recoverRuns) < $recoverRuns) {
                continue; // not ok for long enough: no "recovered" that a flapping window takes back
            }
            $changes[$code] = $status === CheckResult::OK ? self::RECOVERED : self::PROBLEM;
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
     * The notices not told yet, by check code in the order they came - of a check told less than $gapSeconds ago,
     * none yet: they wait and go together in its next message.
     *
     * @param array<mixed> $row the notices row
     * @param array<mixed> $noticed the noticed row
     * @return array<string, array<int, string>> code => [id => text]
     */
    public static function pendingNotices(array $row, array $noticed, int $now = 0, int $gapSeconds = 0): array
    {
        $pending = [];
        foreach ((array)($row['items'] ?? []) as $item) {
            [$id, , $code, $text] = array_pad((array)$item, 4, '');
            $told = (array)($noticed[$code] ?? []);
            if ((int)($told['at'] ?? 0) > $now - $gapSeconds) {
                continue;
            }
            if ((int)$id > (int)($told['id'] ?? 0)) {
                $pending[(string)$code][(int)$id] = (string)$text;
            }
        }
        return $pending;
    }

    /**
     * The checks a scope's alerts are about: its own (scope 0: the shared ones); a website with a channel of its own
     * also the shared ones but GLOBAL_ONLY when *Also post shared problems to website channels* is on.
     *
     * @param array<int|string, array<string, array<mixed>>> $checksRow the "checks" row
     * @return array<string, array<mixed>>
     */
    public static function scopeChecks(array $checksRow, int $scope, bool $withShared): array
    {
        $own = (array)($checksRow[$scope] ?? []);
        return $scope === 0 || !$withShared ? $own
            : $own + array_diff_key((array)($checksRow[0] ?? []), array_flip(self::GLOBAL_ONLY));
    }

    /**
     * The noticed row after a message with $code's notices up to $id went out at $now.
     *
     * @param array<mixed> $noticed
     * @return array<mixed>
     */
    public static function noticed(array $noticed, string $code, int $id, int $now): array
    {
        return [$code => ['id' => $id, 'at' => $now]] + $noticed;
    }
}
