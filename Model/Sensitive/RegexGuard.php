<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Sensitive;

/**
 * Whether a rule's pattern is safe to run on every log entry: a PCRE pattern with delimiters, at most MAX_LENGTH
 * characters, that compiles, has no nested quantifier (the usual catastrophic backtracking, e.g. "(a+)+"), and finishes
 * a worst-case sample within the backtracking limit Detector uses.
 */
class RegexGuard
{
    public const MAX_LENGTH = 300;
    public const BACKTRACK_LIMIT = 100000;

    /** @return string '' when it is safe, else why not */
    public function check(string $pattern): string
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
            foreach ([str_repeat('a', 5000) . '!', str_repeat('1 ', 2500), str_repeat('a@b.', 1000)] as $sample) {
                if ($this->quietly(fn () => preg_match_all($pattern, $sample)) === false) {
                    return 'the pattern is too slow on long lines';
                }
            }
        } finally {
            ini_set('pcre.backtrack_limit', (string)$limit);
        }
        return '';
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
