<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Sensitive;

/**
 * Whether a rule's pattern is safe to run on every log entry: a PCRE pattern with delimiters, at most MAX_LENGTH
 * characters, that compiles, has no nested quantifier (the usual catastrophic backtracking, e.g. "(a+)+"), and finishes
 * worst-case samples within the backtracking limit Detector uses: long runs of one character, and long word and digit
 * runs that end in the pattern's own literals (a pattern is often fast only while its literal is missing). When a
 * rule is saved, also a realistic log sample (sample(): about 80 KB of Monolog lines, JSON context, stack traces,
 * SQL, tokens) within that limit and within $maxMilliseconds - a pattern can be fine on one repeated character and
 * still slow on a mixed log.
 */
class RegexGuard
{
    public const MAX_LENGTH = 300;
    public const BACKTRACK_LIMIT = 100000;
    public const MAX_MILLISECONDS = 10.0;

    private static ?string $sample = null;

    public function __construct(
        private readonly float $maxMilliseconds = self::MAX_MILLISECONDS
    ) {
    }

    /**
     * @param bool $benchmark also on the realistic sample (when a rule is saved; not on every run)
     * @return string '' when it is safe, else why not
     */
    public function check(string $pattern, bool $benchmark = true): string
    {
        if ($pattern === '' || mb_strlen($pattern) > self::MAX_LENGTH) {
            return sprintf('a pattern has 1 to %d characters', self::MAX_LENGTH);
        }
        if (!preg_match('/^([^\w\s\\\\]).*\1[imsxuUXJ]*$/s', $pattern)) {
            return 'a pattern needs delimiters, e.g. /secret_[0-9]+/';
        }
        if ($this->quietly(fn () => preg_match($pattern, '')) === false) {
            return 'the pattern does not compile';
        }
        if (preg_match('/\((?:[^()\\\\]|\\\\.)*[+*}](?:[^()\\\\]|\\\\.)*\)\s*[+*{]/', $pattern)) {
            return 'a nested quantifier (e.g. (a+)+) can make it hang';
        }
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string)self::BACKTRACK_LIMIT);
        try {
            foreach ($this->samples($pattern) as $sample) {
                if ($this->quietly(fn () => preg_match_all($pattern, $sample)) === false) {
                    return 'the pattern is too slow on long lines';
                }
            }
            if ($benchmark) {
                $started = microtime(true);
                if ($this->quietly(fn () => preg_match_all($pattern, self::sample())) === false) {
                    return 'the pattern is too slow on a realistic log sample (it hits the backtracking limit)';
                }
                $ms = (microtime(true) - $started) * 1000;
                if ($ms > $this->maxMilliseconds) {
                    return sprintf('the pattern is too slow on a realistic log sample (%.1f ms, at most %.0f ms)',
                        $ms, $this->maxMilliseconds);
                }
            }
        } finally {
            ini_set('pcre.backtrack_limit', (string)$limit);
        }
        return '';
    }

    /**
     * Long runs of one character, then each of the pattern's first literals (3+ plain characters outside classes and
     * escapes) after a long word run, a digit run and a mixed one, followed by a character that breaks the match.
     *
     * @return string[]
     */
    private function samples(string $pattern): array
    {
        $samples = [str_repeat('a', 5000) . '!', str_repeat('1 ', 2500), str_repeat('a@b.', 1000)];
        $body = (string)preg_replace('/^(.)(.*)\1[a-zA-Z]*$/s', '$2', $pattern);
        // classes, escapes like \d and counts out; an escaped character is a literal
        $body = (string)preg_replace(['/\[(?:\\\\.|[^\]\\\\])*\]/', '/\\\\[a-zA-Z]/', '/\{\d+(?:,\d*)?\}/'], ' ',
            $body);
        $body = (string)preg_replace('/\\\\(.)/', '$1', $body);
        preg_match_all('/[A-Za-z0-9_=:@\/-]{3,}/', $body, $literals);
        foreach (array_slice(array_unique($literals[0]), 0, 3) as $literal) {
            foreach ([str_repeat('a', 5000), str_repeat('1', 5000), str_repeat('a1_', 1700)] as $run) {
                $samples[] = $run . $literal . '!';
            }
        }
        return $samples;
    }

    /** About 80 KB of log text as Magento writes it: entries with JSON context, stack traces, SQL errors, tokens. */
    public static function sample(): string
    {
        if (self::$sample === null) {
            $lines = [];
            for ($i = 0; $i < 120; $i++) {
                $lines[] = sprintf('[2026-10-20T08:%02d:00.123456+00:00] main.ERROR: Unable to send order 000000%d to'
                    . ' customer%d@example.test: Connection could not be established with host smtp.example.test:587'
                    . ' {"exception":"[object] (Magento\\\\Framework\\\\Exception\\\\MailException(code: 0): x at'
                    . ' /var/www/html/vendor/magento/module-email/Model/Transport.php:240)","order_id":"%d",'
                    . '"token":"eyJ' . 'hbGciOiJIUzI1NiJ9.eyJ' . 'zdWIiOiIxMjM0In0.%s"} []', $i % 60, $i, $i, $i,
                    str_repeat('Ab3', 14));
                $lines[] = sprintf('#%d /var/www/html/vendor/magento/framework/Interception/Interceptor.php(%d):'
                    . ' Magento\\Framework\\App\\Http->launch()', $i, $i);
                $lines[] = sprintf("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x-%d'"
                    . " for key 'PRIMARY', query was: INSERT INTO `quote` (`a`, `b`) VALUES (?, ?)", $i);
            }
            $lines[] = str_repeat('=', 200);
            $lines[] = str_repeat('-', 200) . ' ' . str_repeat('9', 40) . ' '
                . str_repeat('QWxhZGRpbjpvcGVuIHNlc2FtZQ', 15);
            self::$sample = implode("\n", $lines);
        }
        return self::$sample;
    }

    /** $run's result without PHP's warning for a pattern that does not compile (preg_* then return false). */
    private function quietly(\Closure $run): mixed
    {
        set_error_handler(fn () => true);
        try {
            return $run();
        } finally {
            restore_error_handler();
        }
    }
}
