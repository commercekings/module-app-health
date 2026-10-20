<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Api\Data\LogEntry;
use CommerceKings\AppHealth\Model\Sensitive\Detector;

/**
 * What of a log entry may leave the server, whichever parser read it: the level and channel (checked), exception class
 * names (checked), where it happened (relative to the Magento root, else the last two path segments, plus the line),
 * a fingerprint, a safe label and - unless *Add the masked log message* is off (it is on by default) - the message
 * masked.
 *
 * First, for all of them, the value after a secret's key word ("password hunter2", "pin=12") and the name after a
 * person's ("customer jane doe") become ***. Normalizing (the fingerprint and the label): URLs, emails, quoted
 * values, hex strings, numbers and paths after their first segment become placeholders, so "Order 1234 of
 * jane@example.com failed" and "Order 99 of joe@example.com failed" are one error. The label is the exception class,
 * else the normalized message's first LABEL_WORDS words with the placeholders and any capitalized word after the
 * first (names) as ***.
 */
class Normalizer
{
    public function __construct(
        private readonly Detector $detector
    ) {
    }

    public const LABEL_WORDS = 3;
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
    /** A secret's key words: the value after them is masked, whatever its length. */
    private const SECRET_WORDS = 'password|passwd|passphrase|pass|pwd|secret|token|api[_-]?key|key|pin|cvv2?|cvc2?';
    /** A person's key words: the one or two words after them are masked. */
    private const NAME_WORDS = 'customer|name|firstname|lastname|username|user|recipient|cardholder|buyer|contact';
    /** Words after a key word that are not a value or a name ("invalid password for", "customer id"). */
    private const NOT_VALUES = ['a', 'already', 'an', 'and', 'are', 'at', 'be', 'been', 'by', 'can', 'cannot', 'change',
        'changed', 'confirmation', 'could', 'data', 'did', 'does', 'email', 'empty', 'entity', 'exists', 'expired',
        'failed', 'field', 'for', 'found', 'from', 'group', 'has', 'have', 'id', 'in', 'incorrect', 'invalid', 'is',
        'length', 'login', 'mismatch', 'missing', 'must', 'no', 'not', 'of', 'on', 'or', 'policy', 'required', 'reset',
        'should', 'the', 'through', 'to', 'type', 'value', 'was', 'were', 'with', 'would', 'wrong'];
    /** IPv6: four or more groups, or "::" with a digit (not "Class::method", not a time). */
    private const IPV6 = '/(?<![\w:])(?:(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}|(?=[0-9a-f:]*\d)(?:[0-9a-f]{1,4}:){1,6}:'
        . '(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,5})?)(?![\w:])/i';
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
        $clean = $this->keyWords($entry->message);
        $normalized = $this->normalize($clean);
        $class = $classes[0] ?? '';
        return [
            'rank' => $rank,
            'level' => $level,
            'channel' => $channel,
            'classes' => $classes,
            'location' => $this->location($entry->file, $entry->line, $root),
            'fingerprint' => substr(hash('sha256', strtolower($channel . '|' . min($rank, 3) . '|' . $class . '|'
                . $normalized)), 0, 8),
            'label' => $class !== '' ? $class : $this->label($normalized, $clean),
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
            // not \b: a hex token after "_" ("token=e2e_3fa9c1d2") is one placeholder too
            '/\b0x[0-9a-f]+\b|(?<![0-9a-f])[0-9a-f]{8}-[0-9a-f-]{27}(?![0-9a-f])'
                . '|(?<![0-9a-f])(?=[0-9a-f]*\d)[0-9a-f]{8,}(?![0-9a-f])/i' => '<hex>',
            '/[+-]?\d+(?:[.,:\/-]\d+)*/' => '<n>',
        ];
        $normalized = (string)preg_replace(array_keys($patterns), array_values($patterns), $message);
        return trim((string)preg_replace('/\s+/u', ' ', $normalized));
    }

    /**
     * One line of at most MESSAGE_LENGTH characters; secrets after key words, names after "customer" and the like,
     * IPv6 addresses, emails, phones, long numbers, tokens, URLs and quotes as ***.
     * PHP's function prefixes ("stream_socket_client(): ") and repeated *** go first; a longer message keeps its
     * trailing reason ("(Connection refused)") and loses its middle ("…").
     */
    public function mask(string $message): string
    {
        $message = $this->keyWords((string)preg_replace('/\b[a-z_]+\(\): /', '', $message));
        // tokens before numbers: a token's digits masked first would leave the rest of it
        $patterns = [
            self::IPV6,
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
     * The value after a secret's key word (after ":" or "=" always; after a space unless it is a word like "for") and
     * the one or two words after a person's key word as ***.
     */
    private function keyWords(string $message): string
    {
        $isValue = fn (string $word): bool => !in_array(strtolower(rtrim($word, '.:!?')), self::NOT_VALUES, true);
        $message = (string)preg_replace_callback('/\b(' . self::SECRET_WORDS . ')\b(\s*[:=]\s*|\s+)'
            . '("[^"]*"|\'[^\']*\'|[^\s,;()]+)/i', fn (array $m) => preg_match('/[:=]/', $m[2]) || $isValue($m[3])
                ? $m[1] . $m[2] . '***' : $m[0], $message);
        return (string)preg_replace_callback('/\b(' . self::NAME_WORDS . ')(\s*[:=]\s*|\s+)(\p{L}[\p{L}\'.-]*)'
            . '(?:(\s+)(\p{L}[\p{L}\'.-]*))?/iu', function (array $m) use ($isValue): string {
                if (!$isValue($m[3])) {
                    return $m[0];
                }
                $second = isset($m[5]) && $m[5] !== '' && $isValue($m[5]);
                return $m[1] . $m[2] . '***' . (!$second && isset($m[5]) ? ($m[4] ?? '') . $m[5] : '');
            }, $message);
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

    /**
     * The first words, every placeholder and name-like word as ***. A lowercase first word with a short number in it
     * ("e2e", "v2", "oauth2") stays as written: it names something, it is not a value.
     */
    private function label(string $normalized, string $message): string
    {
        $words = array_slice(preg_split('/ /', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::LABEL_WORDS);
        $first = (string)strtok(trim($message), ' ');
        if ($words !== [] && preg_match('/^[a-z][a-z0-9_-]{0,15}:?$/D', $first) && !preg_match('/\d{4}/', $first)
            && str_contains($words[0], '<n>')) {
            $words[0] = $first;
        }
        foreach ($words as $i => $word) {
            if ($i === 0 && $word === $first) {
                continue;
            }
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
