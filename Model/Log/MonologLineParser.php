<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Api\Data\LogEntry;
use CommerceKings\AppHealth\Api\LogParserInterface;

/**
 * Magento's log format (Monolog's line formatter): "[date] channel.LEVEL: message {context} [extra]", an entry running
 * until the next dated line (stack traces, previous exceptions). Exception classes and the first file:line come from
 * the context ("[object] (Class(code: 0): ... at /path/File.php:84)") or the message ("in /path/File.php:84").
 */
class MonologLineParser implements LogParserInterface
{
    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[T ][^\]]{0,40})\] ([A-Za-z0-9_.-]{1,40})\.([A-Z]+): ?(.*)$/';
    private const CLASS_NAME = '/\[object\] \(([A-Za-z_][A-Za-z0-9_\\\\]{0,119})\(code: /';
    private const LOCATION = '#(/[^\s:()"\'\[\]{}]{1,250}\.(?:php|phtml))(?::| on line |\()(\d{1,6})#';
    private const LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    public function parse(string $data): array
    {
        $entries = [];
        $parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2})/m', $data, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            $firstLine = strtok($part, "\n");
            if ($firstLine === false || !preg_match(self::HEADER, $firstLine, $header)
                || !in_array($header[3], self::LEVELS, true)) {
                continue; // the rest of an entry that started before the offset
            }
            $text = str_replace(['\\/', '\\\\'], ['/', '\\'], $part); // the JSON context's escapes
            preg_match_all(self::CLASS_NAME, $text, $classes);
            $location = preg_match(self::LOCATION, $text, $match) ? $match : null;
            $time = strtotime($header[1]);
            $entries[] = new LogEntry($header[3], $header[2], $this->message($header[4]), $classes[1],
                $location[1] ?? null, isset($location[2]) ? (int)$location[2] : null, $time === false ? null : $time,
                $part);
        }
        return $entries;
    }

    /**
     * The first line without Monolog's context and extra: up to a JSON context that starts on it ({"...", which may
     * run over the next lines), else without the trailing "{...} [...]" or "[] []".
     */
    private function message(string $line): string
    {
        if (preg_match('/^(.*?)\s+\{"/', $line, $match)) {
            return trim($match[1]);
        }
        return trim((string)preg_replace('/\s+(?:\{.*\}|\[.*\])\s+(?:\{.*\}|\[.*\])\s*$/', '', $line));
    }
}
