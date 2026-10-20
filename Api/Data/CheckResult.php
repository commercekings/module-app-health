<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api\Data;

/**
 * What a check found: a status, a short data-free detail (e.g. "catalog_product_price invalid for 2 h") and optional
 * counts of what happened since the check's last run, added up for the daily summary.
 *
 * Counts: "runs" => 12 is printed as "12 runs"; "errors:sitemap_generate" => 2 breaks "errors" down by a name
 * ("2 errors (sitemap_generate x2)"). Names are codes or class names only. The detail is one line of at most
 * DETAIL_LENGTH characters, and anything that looks like an email address is replaced.
 *
 * @api
 */
class CheckResult
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public const DETAIL_LENGTH = 200;

    /** A count's total ("errors") and optional item ("sitemap_generate", a class name, a queue name). */
    public const COUNT_KEY = '/^[a-z][a-z ]{0,31}(:[A-Za-z0-9_.\\\\-]{1,120})?$/D';

    public readonly string $detail;

    /** @var array<string, int> */
    public readonly array $counts;

    /**
     * @param array<string, int> $counts
     * @throws \InvalidArgumentException for an unknown status, a count key that is not a name or a negative count
     */
    public function __construct(
        public readonly string $status,
        string $detail = '',
        array $counts = []
    ) {
        if (!in_array($status, [self::OK, self::WARN, self::FAIL], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown check status "%s".', $status));
        }
        foreach ($counts as $key => $count) {
            if (!is_string($key) || !preg_match(self::COUNT_KEY, $key) || !is_int($count) || $count < 0) {
                throw new \InvalidArgumentException('Check counts are names with whole numbers of at least 0.');
            }
        }
        $this->detail = self::clean($detail);
        $this->counts = $counts;
    }

    /** @param array<string, int> $counts */
    public static function ok(string $detail = '', array $counts = []): self
    {
        return new self(self::OK, $detail, $counts);
    }

    /** @param array<string, int> $counts */
    public static function warn(string $detail, array $counts = []): self
    {
        return new self(self::WARN, $detail, $counts);
    }

    /** @param array<string, int> $counts */
    public static function fail(string $detail, array $counts = []): self
    {
        return new self(self::FAIL, $detail, $counts);
    }

    /** 0 for ok, 1 for warn, 2 for fail: the worse of two statuses is the higher. */
    public static function rank(string $status): int
    {
        return match ($status) {
            self::FAIL => 2,
            self::WARN => 1,
            default => 0,
        };
    }

    public static function fromRank(int $rank): string
    {
        return [self::OK, self::WARN, self::FAIL][max(0, min(2, $rank))];
    }

    /** One line, no email addresses, at most DETAIL_LENGTH characters. */
    private static function clean(string $detail): string
    {
        $detail = trim((string)preg_replace('/\s+/u', ' ', $detail));
        $detail = (string)preg_replace('/[^\s@]+@[^\s@]+/u', '[address]', $detail);
        return mb_strlen($detail) > self::DETAIL_LENGTH
            ? mb_substr($detail, 0, self::DETAIL_LENGTH - 1) . '…' : $detail;
    }
}
