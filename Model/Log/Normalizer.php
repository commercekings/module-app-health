<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Api\Data\LogEntry;
use CommerceKings\AppHealth\Model\Sensitive\Detector;

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
    public function __construct(
        private readonly Detector $detector
    ) {
    }

    public const LABEL_WORDS = 8;
    public const MESSAGE_LENGTH = 160;
    /** WARNING = 1, ERROR = 2, CRITICAL and above = 3; below WARNING 0. */
    public const RANKS = ['WARNING' => 1, 'ERROR' => 2, 'CRITICAL' => 3, 'ALERT' => 3, 'EMERGENCY' => 3];
    private const CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_\\\\]{0,119}$/D';
    /** Mail transport failures: Magento's MailException, Symfony Mailer and Laminas Mail exceptions. */
    private const MAIL_CLASS = '/(^|\\\\)MailException$|^Symfony\\\\Component\\\\Mailer\\\\|^Laminas\\\\Mail\\\\/';
    /** Known mail transport failures, by the raw message: pattern, what it means. */
    private const MAIL_REASONS = [
        'dns' => ['/getaddrinfo|Name or service not known|nodename nor servname|No such host'
            . '|php_network_getaddresses/i',
            'the mail server\'s host name was not found (DNS)'],
        'tls' => ['/certificate verify failed|SSL operation failed|TLS|crypto|OpenSSL/i',
            'the secure (TLS) connection to the mail server failed'],
        'auth' => ['/authenticat|535|Username and Password not accepted|Invalid login/i',
            'the mail server refused the login'],
        'timeout' => ['/timed? ?out|Connection timed out|Operation timed out/i',
            'the mail server did not answer (timed out)'],
        'refused' => ['/Connection refused|Unable to connect|could not be established|Connection reset/i',
            'the mail server refused the connection'],
    ];
    /** ... and Magento's own messages for them (system.log: the mail transport and the sales email senders). */
    private const MAIL_MESSAGE = '/Transport error while sending email|Unable to send mail|Transport error: Unable/i';

    /**
     * @return array{rank: int, level: string, channel: string, classes: string[], location: ?string,
     *     fingerprint: string, label: string, message: ?string, time: ?int, mail: bool,
     *     sensitive: array<string, int>}|null null below WARNING (rank 0 only with sensitive data found) or for an
     *     unknown level; mail: a mail transport failure; sensitive: rule key => matches (Sensitive\Detector)
     */
    public function safe(LogEntry $entry, string $root, bool $withMessage): ?array
    {
        $rank = self::RANKS[strtoupper($entry->level)] ?? 0;
        // sensitive data first, on the raw text, before anything is masked; below WARNING nothing else is kept
        $sensitive = $this->detector->detect($entry->text !== '' ? $entry->text : $entry->message);
        if ($rank === 0 && $sensitive === []) {
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
            'mail' => $mail = preg_match(self::MAIL_MESSAGE, $entry->message) === 1
                || array_filter($classes, fn ($class) => preg_match(self::MAIL_CLASS, $class)) !== [],
            'mail_reason' => $mail ? $this->mailReason($entry->message) : null,
            'sensitive' => $sensitive,
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

    /**
     * One line of at most MESSAGE_LENGTH characters; emails, phones, long numbers, tokens, URLs and quotes as ***.
     * PHP's function prefixes ("stream_socket_client(): ") and repeated *** go first; a longer message keeps its
     * trailing reason ("(Connection refused)") and loses its middle ("…").
     */
    public function mask(string $message): string
    {
        $message = (string)preg_replace('/\b[a-z_]+\(\): /', '', $message);
        // tokens before numbers: a token's digits masked first would leave the rest of it
        $patterns = [
            '/"[^"]{0,500}"|\'[^\']{0,500}\'|“[^”]{0,500}”|`[^`]{0,500}`/u',
            '#\b[a-z][a-z0-9+.-]{1,9}://[^\s"\'()]+#i',
            '/[^\s@<>"\'()]+@[^\s@<>"\'()]+/',
            '/\b(?=[A-Za-z0-9_-]*\d)(?=[A-Za-z0-9_-]*[A-Za-z])[A-Za-z0-9_-]{12,}\b/',
            '/[A-Za-z0-9+\/_-]{24,}={0,2}/',
            '/\+?\d[\d ()\/.-]{5,}\d/',
            '/\d{4,}/',
        ];
        $masked = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace($patterns, '***', $message)));
        $masked = (string)preg_replace('/\*\*\*(?:[:\/@ ]*\*\*\*)+/', '***', $masked);
        if (mb_strlen($masked) <= self::MESSAGE_LENGTH) {
            return $masked;
        }
        $tail = preg_match('/\([^()]{3,60}\)\.?$/u', $masked, $reason) ? ' ' . $reason[0] : '';
        return mb_substr($masked, 0, self::MESSAGE_LENGTH - 1 - mb_strlen($tail)) . '…' . $tail;
    }

    /**
     * What kind of mail transport failure the raw message is, and the port when it names one (never the host):
     * refused, timeout, auth, tls, dns or other.
     *
     * @return array{code: string, reason: string, port: ?int}
     */
    public function mailReason(string $message): array
    {
        $port = preg_match('/[\w.\]-]:(\d{2,5})\b/', $message, $match) ? (int)$match[1] : null;
        foreach (self::MAIL_REASONS as $code => [$pattern, $reason]) {
            if (preg_match($pattern, $message)) {
                return ['code' => $code, 'reason' => $reason, 'port' => $port];
            }
        }
        return ['code' => 'other', 'reason' => 'the mail server could not be used', 'port' => $port];
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
