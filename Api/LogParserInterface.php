<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api;

use CommerceKings\AppHealth\Api\Data\LogEntry;

/**
 * Turns a source's new data into entries (Model\Log\LogSourcePool "sources": source and parser). An entry may span
 * several lines (a stack trace). Return every entry, at any level, with its whole text: entries below WARNING are only
 * scanned for sensitive data. App health's own code makes them safe (fingerprint, label, masking) - a parser hands
 * over what it read and never decides what leaves the server.
 *
 * @api
 */
interface LogParserInterface
{
    /** @return LogEntry[] in order */
    public function parse(string $data): array;
}
