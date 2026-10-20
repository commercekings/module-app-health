<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\AppHealth\Model\Log\LogReader;

/**
 * The new entries of the exception check's log sources (var/log/exception.log by default), shared by the exception
 * and sending checks: counted, by exception class, and those with a mail transport class.
 */
class ExceptionLog
{
    public const CHECK = 'exceptions';

    public function __construct(
        private readonly LogReader $reader
    ) {
    }

    /**
     * @return array{entries: int, classes: array<string, int>, mail: int, partial: bool, skipped: int,
     *     safe: list<array<string, mixed>>} entries, the first class of each by count ("other" without one),
     *     entries with a mail transport class, whether part of a backlog was left out (and its bytes), and the safe
     *     entries
     */
    public function newEntries(): array
    {
        $read = $this->reader->entries(self::CHECK);
        $result = ['entries' => count($read['entries']), 'classes' => [], 'mail' => 0, 'partial' => $read['partial'],
            'skipped' => $read['skipped'], 'safe' => $read['entries']];
        foreach ($read['entries'] as $entry) {
            $first = $entry['classes'][0] ?? 'other';
            $result['classes'][$first] = ($result['classes'][$first] ?? 0) + 1;
            $result['mail'] += (int)$entry['mail'];
        }
        return $result;
    }
}
