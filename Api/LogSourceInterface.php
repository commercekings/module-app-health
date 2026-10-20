<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api;

use CommerceKings\AppHealth\Api\Data\LogChunk;

/**
 * Where log lines come from (a file in var/log, a stream, a service), registered with its parser in di.xml
 * (Model\Log\LogSourcePool "sources"). Called once per run: return what was added since $cursor, at most $maxBytes,
 * and the cursor to continue from - App health stores it. Read only new data, never the whole history.
 *
 * @api
 */
interface LogSourceInterface
{
    /** Unique, [a-z0-9_], at most 32 characters, e.g. "system". */
    public function getCode(): string;

    /**
     * @param array<string, int|string> $cursor what the last read returned ([] on the first read: start at the end)
     */
    public function read(array $cursor, int $maxBytes): LogChunk;
}
