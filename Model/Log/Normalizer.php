<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Api\Data\LogEntry;

/**
 * What of a log entry may leave the server, whichever parser read it: the level and channel (checked), exception class
 * names (checked), where it happened (relative to the Magento root, else the last two path segments, plus the line),
 * a fingerprint and a safe label - and, opt-in, the message masked.
 *
 * Normalizing (the fingerprint and the label): URLs, emails, quoted values, hex strings, numbers and paths after their
 * first segment become placeholders, so "Order 1234 of jane@example.com failed" and "Order 99 of joe@example.com
 * failed" are one error. The label is the exception class, else the normalized message's first LABEL_WORDS words
 * with the placeholders and any capitalized word after the first (names) as ***.
 */
class Normalizer
{
    public const LABEL_WORDS = 8;
    public const MESSAGE_LENGTH = 160;
    /** WARNING = 1, ERROR = 2, CRITICAL and above = 3. */
    public const RANKS = ['WARNING' => 1, 'ERROR' => 2, 'CRITICAL' => 3, 'ALERT' => 3, 'EMERGENCY' => 3];
    private const CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_\\\\]{0,119}$/D';

    /**
     * @return array{rank: int, level: string, channel: string, classes: string[], location: ?string,
     *     fingerprint: string, label: string, message: ?string, time: ?int}|null null below WARNING or for an unknown
     *     level
     */
    public function safe(LogEntry $entry, string $root, bool $withMessage): ?array
    {
        $rank = self::RANKS[strtoupper($entry->level)] ?? 0;
        if ($rank === 0) {
            return null;
        }
        $level = strtoupper($entry->level);
        $channel = preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', $entry->channel) ? $entry->channel : 'log';
        $classes = array_values(array_filter($entry->classes, fn ($class) => preg_match(self::CLASS_NAME, $class)));
        $normalized = $this->normalize($entry->message);
        $class = $classes[0] ?? '';
        return [
            'rank' => $rank,
            'level' => $level,
            'channel' => $channel,
            'classes' => $classes,
            'location' => $this->location($entry->file, $entry->line, $root),
            'fingerprint' => substr(hash('sha256', strtolower($channel . '|' . min($rank, 3) . '|' . $class . '|'
                . $normalized)), 0, 8),
            'label' => $class !== '' ? $class : $this->label($normalized),
            'message' => $withMessage ? $this->mask($entry->message) : null,
            'time' => $entry->time,
        ];
    }

    /** "ERROR", "CRITICAL" (with ALERT and EMERGENCY) or "WARNING". */
    public static function group(int $rank): string
    {
        return [1 => 'WARNING', 2 => 'ERROR', 3 => 'CRITICAL'][$rank] ?? 'WARNING';
    }

    /** Placeholders for every variable part; one space between words. */
    public function normalize(string $message): string
    {
        $patterns = [
            '#\b[a-z][a-z0-9+.-]{1,9}://\S+#i' => '<url>',
            '/[^\s@<>"\'()]+@[^\s@<>"\'()]+/' => '<email>',
            '/"[^"]{0,500}"|\'[^\']{0,500}\'|“[^”]{0,500}”|`[^`]{0,500}`/u' => '<q>',
            '#(/[^\s/:()]+)(?:/[^\s:()]+)+#' => '$1/<path>',
            '/\b0x[0-9a-f]+\b|\b[0-9a-f]{8}-[0-9a-f-]{27}\b|\b(?=[0-9a-f]*\d)[0-9a-f]{8,}\b/i' => '<hex>',
            '/[+-]?\d+(?:[.,:\/-]\d+)*/' => '<n>',
        ];
        $normalized = (string)preg_replace(array_keys($patterns), array_values($patterns), $message);
        return trim((string)preg_replace('/\s+/u', ' ', $normalized));
    }

    /** One line of at most MESSAGE_LENGTH characters; emails, phones, long numbers, tokens, URLs and quotes as ***. */
    public function mask(string $message): string
    {
        // tokens before numbers: a token's digits masked first would leave the rest of it
        $patterns = [
            '#\b[a-z][a-z0-9+.-]{1,9}://\S+#i',
            '/[^\s@<>"\'()]+@[^\s@<>"\'()]+/',
            '/"[^"]{0,500}"|\'[^\']{0,500}\'|“[^”]{0,500}”|`[^`]{0,500}`/u',
            '/\b(?=[A-Za-z0-9_-]*\d)(?=[A-Za-z0-9_-]*[A-Za-z])[A-Za-z0-9_-]{12,}\b/',
            '/[A-Za-z0-9+\/_-]{24,}={0,2}/',
            '/\+?\d[\d ()\/.-]{5,}\d/',
            '/\d{4,}/',
        ];
        $masked = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace($patterns, '***', $message)));
        return mb_strlen($masked) > self::MESSAGE_LENGTH
            ? mb_substr($masked, 0, self::MESSAGE_LENGTH - 1) . '…' : $masked;
    }

    /** The first words, every placeholder and name-like word as ***. */
    private function label(string $normalized): string
    {
        $words = array_slice(preg_split('/ /', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::LABEL_WORDS);
        foreach ($words as $i => $word) {
            if (str_contains($word, '<') || ($i > 0 && preg_match('/\p{Lu}/u', $word))
                || !preg_match('/^[\p{L}\p{P}]+$/u', $word)) {
                $words[$i] = '***';
            }
        }
        $label = (string)preg_replace('/\*\*\*( \*\*\*)+/', '***', implode(' ', $words));
        return $label !== '' ? $label : '***';
    }

    /** "app/code/Vendor/Module/Model/X.php:84"; the last two path segments when outside the Magento root. */
    private function location(?string $file, ?int $line, string $root): ?string
    {
        if ($file === null || !preg_match('#^/[^\s:()"\'\[\]{}]{1,250}\.(?:php|phtml)$#D', $file)) {
            return null;
        }
        $root = rtrim($root, '/') . '/';
        $path = str_starts_with($file, $root) ? substr($file, strlen($root))
            : '…/' . implode('/', array_slice(explode('/', $file), -2));
        return $path . ($line !== null && $line > 0 ? ':' . $line : '');
    }
}
