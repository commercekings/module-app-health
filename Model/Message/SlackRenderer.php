<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Message;

use CommerceKings\AppHealth\Api\Data\TeamMessageContent;
use CommerceKings\AppHealth\Api\Data\TeamMessageSection;
use CommerceKings\AppHealth\Api\Data\TeamMessageTable;
use CommerceKings\AppHealth\Api\TeamMessageRendererInterface;

/**
 * Slack's mrkdwn, without emoji: the title bold on the first line (what a phone notification shows), each section
 * after a blank line - a strong heading bold, list lines as "• ", a table in a code block (so its columns line up)
 * under its bold title - then "What to do: <hint>" lines:
 *
 *   *Problem: Cron stopped · Acme Shop · shop.example.com*
 *
 *   No job has run since 10:39 (65 min).
 *
 *   What to do: Is the cron container (or crontab) running? ...
 *
 * The provider (module-notification-slack) escapes the text it posts.
 */
class SlackRenderer implements TeamMessageRendererInterface
{
    /** A table's cell is cut to this many characters. */
    public const CELL_WIDTH = 28;

    public function render(TeamMessageContent $message): string
    {
        $text = '*' . $message->title . '*';
        foreach ($message->sections as $section) {
            $part = $this->section($section);
            $text .= $part !== '' ? "\n\n" . $part : '';
        }
        $hints = array_filter($message->hints, fn (string $hint) => $hint !== '');
        return $text . ($hints !== [] ? "\n\n" . implode("\n", array_map(fn (string $hint) => 'What to do: ' . $hint,
            $hints)) : '');
    }

    /** One section: its heading, its tables, its lines - each on a line of its own; '' when it has nothing. */
    public function section(TeamMessageSection $section): string
    {
        $parts = [];
        if ($section->heading !== null) {
            $parts[] = $section->strongHeading ? '*' . $section->heading . '*' : $section->heading;
        }
        foreach ($section->tables as $table) {
            $parts[] = '*' . $table->title . "*\n```\n" . self::table($table) . "\n```";
        }
        if ($section->lines !== []) {
            $parts[] = implode($section->spaced ? "\n\n" : "\n", array_map(fn (string $line) => $section->bullets
                ? '• ' . $line : $line, $section->lines));
        }
        return implode("\n", $parts);
    }

    /** Columns padded to their widest cell: words left, numbers right; trailing spaces dropped. */
    public static function table(TeamMessageTable $table): string
    {
        $rows = array_map(fn (array $row) => array_map(fn ($cell) => mb_substr((string)$cell, 0, self::CELL_WIDTH),
            array_values($row)), array_merge([$table->columns], $table->rows));
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }
        return implode("\n", array_map(fn (array $row) => rtrim(implode('  ', array_map(
            fn (string $cell, int $i) => $i < $table->leftColumns ? $cell . str_repeat(' ', $widths[$i] - mb_strlen($cell))
                : str_repeat(' ', $widths[$i] - mb_strlen($cell)) . $cell, $row, array_keys($row)))), $rows));
    }
}
