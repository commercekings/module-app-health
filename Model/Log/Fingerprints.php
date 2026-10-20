<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\RunState;

/**
 * The log entries seen (WARNING and above), by fingerprint, in the "fingerprints" row: label, level, channel, where,
 * the latest masked message (unless it is turned off), first and last seen, the count, and when it was last told. At
 * most LIMIT, the least recently seen go first - into the "fingerprints:recent" row (when first seen, when dropped;
 * kept RECENT_SECONDS, at most RECENT_LIMIT), so an error dropped and seen again within a day is not "NEW" again.
 * record() returns what the team should hear at once: a CRITICAL entry seen for the first time ("NEW"), or - for
 * checks that ask - one not told within the repeat interval. ERROR entries go to the hourly status instead: each run's
 * count per fingerprint in the "errors:recent" row ([time, fingerprint, count, new], RECENT_ERRORS_HOURS, at most
 * RECENT_ERRORS_LIMIT). WARNING entries are only counted (the daily summary).
 */
class Fingerprints
{
    public const KEY = 'fingerprints';
    public const LIMIT = 500;
    public const RECENT_KEY = 'fingerprints:recent';
    public const RECENT_LIMIT = 2000;
    public const RECENT_SECONDS = 86400;
    public const ERRORS_KEY = 'errors:recent';
    public const RECENT_ERRORS_HOURS = 3;
    public const RECENT_ERRORS_LIMIT = 500;
    public const LEVELS = ['CRITICAL' => 'Critical', 'ERROR' => 'Error', 'WARNING' => 'Warning'];

    public function __construct(
        private readonly RunState $state,
        private readonly Config $config
    ) {
    }

    /**
     * @param list<array<string, mixed>> $entries Normalizer::safe() entries
     * @return list<array<string, mixed>> CRITICAL news, the most frequent first: kind ("NEW" or "CRITICAL"),
     *     fingerprint, count, and the fingerprint's entry (label, level, channel, location, message, first, last)
     */
    public function record(array $entries, int $now, bool $repeatCritical): array
    {
        $runs = [];
        foreach ($entries as $entry) {
            $fp = (string)$entry['fingerprint'];
            $runs[$fp] ??= $entry + ['count' => 0, 'last' => 0];
            $runs[$fp]['count']++;
            $runs[$fp]['rank'] = max((int)$runs[$fp]['rank'], (int)$entry['rank']);
            $runs[$fp]['last'] = max($runs[$fp]['last'], (int)($entry['time'] ?? $now));
            $runs[$fp]['message'] = $entry['message'] ?? $runs[$fp]['message']; // the latest
        }
        if ($runs === []) {
            return [];
        }
        $known = (array)($this->state->get(self::KEY) ?? []);
        $recent = (array)($this->state->get(self::RECENT_KEY) ?? []);
        $recentChanged = false;
        $repeat = $this->config->getRepeatHours() * 3600;
        $news = [];
        $errors = [];
        foreach ($runs as $fp => $run) {
            $seen = $known[$fp] ?? null;
            if ($seen === null && (int)($recent[$fp][1] ?? 0) > $now - self::RECENT_SECONDS) {
                // dropped from the memory within the day: known, told when it was dropped at the latest
                $seen = ['first' => (int)$recent[$fp][0], 'told' => (int)$recent[$fp][1]];
                unset($recent[$fp]);
                $recentChanged = true;
            }
            $level = Normalizer::group((int)$run['rank']);
            if ($level === 'ERROR') {
                $errors[] = [$now, (string)$fp, (int)$run['count'], $seen === null ? 1 : 0];
            }
            $kind = match (true) {
                $level !== 'CRITICAL' => null,
                $seen === null => 'NEW',
                $repeatCritical && $level === 'CRITICAL' && (int)($seen['told'] ?? 0) <= $now - $repeat => 'CRITICAL',
                default => null,
            };
            $known[$fp] = ['label' => $run['label'], 'level' => $level, 'channel' => $run['channel'],
                'location' => $run['location'], 'message' => $run['message'] ?? null,
                'first' => (int)($seen['first'] ?? $now), 'last' => max((int)($seen['last'] ?? 0), $run['last']),
                'count' => (int)($seen['count'] ?? 0) + $run['count'],
                'told' => $kind !== null ? $now : (int)($seen['told'] ?? 0), 'seen' => $now];
            if ($kind !== null) {
                $news[] = ['kind' => $kind, 'fingerprint' => $fp, 'count' => $run['count']] + $known[$fp];
            }
        }
        if (count($known) > self::LIMIT) {
            // the least recently seen (by run, then by the log's own time) go
            uasort($known, fn ($a, $b) => [(int)($b['seen'] ?? $b['last']), (int)$b['last']]
                <=> [(int)($a['seen'] ?? $a['last']), (int)$a['last']]);
            foreach (array_slice($known, self::LIMIT, null, true) as $fp => $dropped) {
                $recent[$fp] = [(int)$dropped['first'], $now];
            }
            $known = array_slice($known, 0, self::LIMIT, true);
            $recentChanged = true;
        }
        if ($recentChanged) {
            $recent = array_filter($recent, fn ($item) => (int)($item[1] ?? 0) > $now - self::RECENT_SECONDS);
            uasort($recent, fn ($a, $b) => (int)$b[1] <=> (int)$a[1]);
            $this->state->set(self::RECENT_KEY, array_slice($recent, 0, self::RECENT_LIMIT, true));
        }
        $this->state->set(self::KEY, $known);
        if ($errors !== []) {
            $kept = array_filter((array)($this->state->get(self::ERRORS_KEY) ?? []),
                fn ($item) => is_array($item) && (int)($item[0] ?? 0) > $now - self::RECENT_ERRORS_HOURS * 3600);
            $this->state->set(self::ERRORS_KEY, array_slice(array_merge(array_values($kept), $errors),
                -self::RECENT_ERRORS_LIMIT));
        }
        usort($news, fn ($a, $b) => $b['count'] <=> $a['count']);
        return $news;
    }

    /**
     * One error as the summary shows it: "Unable to send order (14×, 09:50, new)" - its label (an exception's class
     * without its namespace), the count, the last time ("{t:...}", shown in each recipient's time zone) and "new" when
     * first seen within the day.
     *
     * @param array<string, mixed> $known the fingerprint's entry
     */
    public static function line(array $known, int $count, bool $new): string
    {
        return self::name($known) . ' (' . Format::number($count) . '×'
            . (!empty($known['last']) ? ', ' . Format::at((int)$known['last']) : '') . ($new ? ', new' : '') . ')';
    }

    /**
     * A notice for the live alert, one fact per line: "Error: Unable to send order (09:50)", "Where:
     * app/code/A/B.php:84" and, when it is on, "Message: <the masked message>".
     *
     * @param array<string, mixed> $news a Fingerprints::record() item
     */
    public static function notice(array $news): string
    {
        $count = (int)$news['count'];
        $lines = [(self::LEVELS[$news['level'] ?? 'ERROR'] ?? 'Error') . ': ' . self::name($news) . ' ('
            . ($count > 1 ? Format::number($count) . '×, ' : '') . Format::at((int)$news['last']) . ')'];
        if (!empty($news['location'])) {
            $lines[] = 'Where: ' . $news['location'];
        }
        $message = (string)($news['message'] ?? '');
        if ($message !== '' && $message !== (string)($news['label'] ?? '')) {
            $lines[] = 'Message: ' . $message;
        }
        return implode("\n", $lines);
    }

    /**
     * The label, an exception's class without its namespace.
     *
     * @param array<string, mixed> $known
     */
    public static function name(array $known): string
    {
        $label = (string)($known['label'] ?? 'Unknown error');
        return preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $label) && str_contains($label, '\\')
            ? substr((string)strrchr($label, '\\'), 1) : $label;
    }
}
