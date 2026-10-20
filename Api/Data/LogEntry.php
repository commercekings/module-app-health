<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api\Data;

/**
 * One log entry as a parser read it. Nothing of it is sent as it is: App health builds the fingerprint, the safe
 * label and the masked message (on by default) from $message and $classes, shows $file relative to the Magento root,
 * and scans $message and $text for sensitive data (rule names and counts only).
 *
 * @api
 */
class LogEntry
{
    /**
     * @param string $level Monolog's: WARNING, ERROR, CRITICAL, ALERT or EMERGENCY
     * @param string $message the entry's message, without context or stack trace
     * @param string[] $classes exception class names, the outermost first
     * @param string|null $file where it happened, an absolute path; null when unknown
     * @param int|null $time unix time; null when unknown
     * @param string $text the whole entry as read (context, trace) - only scanned for sensitive data, never kept
     */
    public function __construct(
        public readonly string $level,
        public readonly string $channel,
        public readonly string $message,
        public readonly array $classes = [],
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly ?int $time = null,
        public readonly string $text = ''
    ) {
    }
}
