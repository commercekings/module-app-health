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
}
