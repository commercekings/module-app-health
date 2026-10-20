<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api\Data;

/**
 * What a log source read: the new data (whole entries; a half-written last one belongs to the next read), the cursor
 * to continue from, and whether more was waiting than could be read (the counts are then a minimum).
 *
 * @api
 */
class LogChunk
{
    /** @param array<string, int|string> $cursor */
    public function __construct(
        public readonly string $data,
        public readonly array $cursor,
        public readonly bool $partial = false
    ) {
    }
}
