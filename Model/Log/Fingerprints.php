<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\RunState;

/**
 * The log entries seen (WARNING and above), by fingerprint, in the "fingerprints" row: label, level, channel, where,
 * the latest masked message (when it is on), first and last seen, the count, and when it was last told. At most LIMIT,
 * the least recently seen go first. record() returns what the team should hear of this run: an error (ERROR and
 * above) seen for the first time ("NEW"), or - for checks that ask - a CRITICAL one not told within the repeat
 * interval.
 */
class Fingerprints
{
    public const KEY = 'fingerprints';
    public const LIMIT = 500;
    public const EMOJI = ['CRITICAL' => '🔴', 'ERROR' => '🟠', 'WARNING' => '🟡'];

    public function __construct(
        private readonly RunState $state,
        private readonly Config $config
    ) {
    }

    /**
     * @param list<array<string, mixed>> $entries Normalizer::safe() entries
     * @return list<array<string, mixed>> news, the most frequent first: kind ("NEW" or "CRITICAL"), fingerprint,
     *     count, and the fingerprint's entry (label, level, channel, location, message, first, last)
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
        $repeat = $this->config->getRepeatHours() * 3600;
        $news = [];
        foreach ($runs as $fp => $run) {
            $seen = $known[$fp] ?? null;
            $level = Normalizer::group((int)$run['rank']);
            $kind = match (true) {
                (int)$run['rank'] < 2 => null,
                $seen === null => 'NEW',
                $repeatCritical && $level === 'CRITICAL' && (int)($seen['told'] ?? 0) <= $now - $repeat => 'CRITICAL',
                default => null,
            };
            $known[$fp] = ['label' => $run['label'], 'level' => $level, 'channel' => $run['channel'],
                'location' => $run['location'], 'message' => $run['message'] ?? null,
                'first' => (int)($seen['first'] ?? $now), 'last' => max((int)($seen['last'] ?? 0), $run['last']),
                'count' => (int)($seen['count'] ?? 0) + $run['count'],
                'told' => $kind !== null ? $now : (int)($seen['told'] ?? 0)];
            if ($kind !== null) {
                $news[] = ['kind' => $kind, 'fingerprint' => $fp, 'count' => $run['count']] + $known[$fp];
            }
        }
        if (count($known) > self::LIMIT) {
            uasort($known, fn ($a, $b) => (int)$b['last'] <=> (int)$a['last']);
            $known = array_slice($known, 0, self::LIMIT, true);
        }
        $this->state->set(self::KEY, $known);
        usort($news, fn ($a, $b) => $b['count'] <=> $a['count']);
        return $news;
    }

    /**
     * One error as alerts and the summary show it: "🆕 PaymentException: Card *** declined ×3 · 14:02 ·
     * app/code/Vendor/Pay/Model/Gateway.php:120 · #a1b2c3d4" - the masked message when there is one, else the label;
     * "{t:...}" is the last time, shown in each recipient's time zone.
     *
     * @param array<string, mixed> $known the fingerprint's entry
     */
    public static function line(string $fp, array $known, int $count, bool $new, bool $emoji): string
    {
        $label = (string)($known['label'] ?? '#' . $fp);
        $class = preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $label) && str_contains($label, '\\')
            ? substr((string)strrchr($label, '\\'), 1) : '';
        $message = (string)($known['message'] ?? '');
        $text = $class !== '' ? $class . ($message !== '' ? ': ' . $message : '')
            : ($message !== '' ? $message : $label);
        return ($new ? '🆕 ' : '') . ($emoji ? (self::EMOJI[$known['level'] ?? 'ERROR'] ?? '🟠') . ' ' : '') . $text
            . ' ×' . Format::number($count) . (!empty($known['last']) ? ' · ' . Format::at((int)$known['last']) : '')
            . (!empty($known['location']) ? ' · ' . $known['location'] : '') . ' · #' . $fp;
    }

    /** A notice for the live alert: the same line, with the level's emoji. */
    public static function notice(array $news): string
    {
        return self::line((string)$news['fingerprint'], $news, (int)$news['count'], $news['kind'] === 'NEW', true);
    }
}
