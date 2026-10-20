<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\RunState;

/**
 * The log errors seen (ERROR and above), by fingerprint, in the "fingerprints" row: label, level, channel, where, first
 * and last seen, the count, and when it was last told. At most LIMIT, the least recently seen go first. record()
 * returns what the team should hear of this run: a fingerprint seen for the first time ("NEW"), or - for checks that
 * ask - a CRITICAL one not told within the repeat interval.
 */
class Fingerprints
{
    public const KEY = 'fingerprints';
    public const LIMIT = 500;

    public function __construct(
        private readonly RunState $state,
        private readonly Config $config
    ) {
    }

    /**
     * @param list<array<string, mixed>> $entries Normalizer::safe() entries
     * @return list<array{kind: string, fingerprint: string, count: int, level: string, channel: string,
     *     label: string, location: ?string, message: ?string}> news, the most frequent first
     */
    public function record(array $entries, int $now, bool $repeatCritical): array
    {
        $runs = [];
        foreach ($entries as $entry) {
            if ((int)$entry['rank'] < 2) {
                continue;
            }
            $fp = (string)$entry['fingerprint'];
            $runs[$fp] ??= $entry + ['count' => 0];
            $runs[$fp]['count']++;
            $runs[$fp]['rank'] = max((int)$runs[$fp]['rank'], (int)$entry['rank']);
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
                $seen === null => 'NEW',
                $repeatCritical && $level === 'CRITICAL' && (int)($seen['told'] ?? 0) <= $now - $repeat => 'CRITICAL',
                default => null,
            };
            $known[$fp] = ['label' => $run['label'], 'level' => $level, 'channel' => $run['channel'],
                'location' => $run['location'], 'first' => (int)($seen['first'] ?? $now), 'last' => $now,
                'count' => (int)($seen['count'] ?? 0) + $run['count'],
                'told' => $kind !== null ? $now : (int)($seen['told'] ?? 0)];
            if ($kind !== null) {
                $news[] = ['kind' => $kind, 'fingerprint' => $fp, 'count' => $run['count'], 'level' => $level,
                    'channel' => (string)$run['channel'], 'label' => (string)$run['label'],
                    'location' => $run['location'], 'message' => $run['message']];
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

    /** "NEW ERROR main: Unable to send *** x3 at app/code/X.php:84 #a1b2c3d4 - ***" */
    public static function notice(array $news): string
    {
        return ($news['kind'] === 'NEW' ? 'NEW ' . $news['level'] : $news['level']) . ' ' . $news['channel'] . ': '
            . $news['label'] . ' x' . $news['count'] . ($news['location'] ? ' at ' . $news['location'] : '')
            . ' #' . $news['fingerprint'] . ($news['message'] ? ' - "' . $news['message'] . '"' : '');
    }
}
