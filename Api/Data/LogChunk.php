<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api\Data;

/**
 * What a log source read: the new data (whole entries; a half-written last one belongs to the next read), the cursor
 * to continue from, whether more was waiting than could be read (the counts are then a minimum) and how many bytes
 * were left out for that (a source should read the newest).
 *
 * @api
 */
class LogChunk
{
    /** @param array<string, int|string> $cursor */
    public function __construct(
        public readonly string $data,
        public readonly array $cursor,
        public readonly bool $partial = false,
        public readonly int $skippedBytes = 0
    ) {
    }
}
