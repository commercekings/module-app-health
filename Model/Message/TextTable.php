<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Message;

use CommerceKings\AppHealth\Api\Data\TeamMessageTable;

/** A table as aligned text, for any renderer: no markup, so each puts it where its channel lines it up. */
class TextTable
{
    /** A cell is cut to this many characters. */
    public const CELL_WIDTH = 28;

    /** Columns padded to their widest cell: words left, numbers right; trailing spaces dropped. */
    public static function lines(TeamMessageTable $table): string
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
            fn (string $cell, int $i) => $i < $table->leftColumns
                ? $cell . str_repeat(' ', $widths[$i] - mb_strlen($cell))
                : str_repeat(' ', $widths[$i] - mb_strlen($cell)) . $cell, $row, array_keys($row)))), $rows));
    }
}
