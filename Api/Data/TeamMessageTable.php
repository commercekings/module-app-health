<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api\Data;

/**
 * A table in a team message (e.g. a server's containers): a title, column names and rows of cells - text only, the
 * renderer lines them up (and may cut a long cell). The first $leftColumns columns are words (left-aligned), the rest
 * numbers (right-aligned).
 *
 * @api
 */
class TeamMessageTable
{
    /**
     * @param string[] $columns
     * @param list<string[]> $rows each as many cells as columns
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly int $leftColumns = 1
    ) {
    }
}
