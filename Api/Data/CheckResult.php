<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api\Data;

/**
 * What a check found: a status, a short data-free detail (e.g. "catalog_product_price invalid for 2 h") and optional
 * counts of what happened since the check's last run, added up for the daily summary.
 *
 * Counts: "runs" => 12 is printed as "12 runs"; "errors:sitemap_generate" => 2 breaks "errors" down by a name
 * ("2 errors (sitemap_generate x2)"); "fp:<fingerprint>" counts a log error for the summary's top errors. Names are
 * codes or class names only. The detail is one line of at most DETAIL_LENGTH characters, and anything that looks like
 * an email address is replaced.
 *
 * Causes say why (Cause: what was found and where to look), as far as Magento's data shows it.
 *
 * The title says what is wrong in a few words ("Cron stopped"), the first line of an alert; without one the alert
 * says "<label> failing". Gauges are numbers as they are now (e.g. unsent emails), shown in the daily summary.
 *
 * Notices are one-off news (e.g. "NEW ERROR ..."), told once with the next alert - not a status, so no "recovered"
 * follows. Each is one line of at most NOTICE_LENGTH characters, cleaned like the detail.
 *
 * @api
 */
class CheckResult
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public const DETAIL_LENGTH = 200;
    public const NOTICE_LENGTH = 400;

    /** A count's total ("errors") and optional item ("sitemap_generate", a class name, a queue name). */
    public const COUNT_KEY = '/^[a-z][a-z ]{0,31}(:[A-Za-z0-9_.\\\\-]{1,120})?$/D';

    public readonly string $detail;

    /** @var array<string, int> */
    public readonly array $counts;

    /** @var string[] */
    public readonly array $notices;

    public readonly string $title;

    /** @var array<string, int> */
    public readonly array $gauges;

    /** @var Cause[] */
    public readonly array $causes;

    /**
     * @param array<string, int> $counts
     * @param string[] $notices each of one or more lines
     * @param array<string, int> $gauges
     * @param Cause[] $causes
     * @throws \InvalidArgumentException for an unknown status, a count key that is not a name or a negative count
     */
    public function __construct(
        public readonly string $status,
        string $detail = '',
        array $counts = [],
        array $notices = [],
        string $title = '',
        array $gauges = [],
        array $causes = []
    ) {
        if (!in_array($status, [self::OK, self::WARN, self::FAIL], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown check status "%s".', $status));
        }
        foreach ($gauges + $counts as $key => $count) {
            if (!is_string($key) || !preg_match(self::COUNT_KEY, $key) || !is_int($count) || $count < 0) {
                throw new \InvalidArgumentException('Check counts are names with whole numbers of at least 0.');
            }
        }
        $this->detail = self::clean($detail, self::DETAIL_LENGTH);
        $this->counts = $counts;
        $this->notices = array_values(array_filter(array_map(fn ($notice) => self::lines((string)$notice,
            self::NOTICE_LENGTH), $notices), fn ($notice) => $notice !== ''));
        $this->title = self::clean($title, 60);
        $this->gauges = $gauges;
        foreach ($causes as $cause) {
            if (!$cause instanceof Cause) {
                throw new \InvalidArgumentException('Causes must be Cause objects.');
            }
        }
        $this->causes = array_values($causes);
    }

    /** @param Cause[] $causes the same result with these causes */
    public function withCauses(array $causes): self
    {
        return new self($this->status, $this->detail, $this->counts, $this->notices, $this->title, $this->gauges,
            $causes);
    }

    /** @param array<string, int> $counts */
    public static function ok(string $detail = '', array $counts = []): self
    {
        return new self(self::OK, $detail, $counts);
    }

    /** @param array<string, int> $counts */
    public static function warn(string $detail, array $counts = [], string $title = ''): self
    {
        return new self(self::WARN, $detail, $counts, [], $title);
    }

    /** @param array<string, int> $counts */
    public static function fail(string $detail, array $counts = [], string $title = ''): self
    {
        return new self(self::FAIL, $detail, $counts, [], $title);
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

    /** One line, no email addresses, at most $length characters. */
    /** clean() for each line of a text of several lines, the empty ones dropped, at most $length in all. */
    private static function lines(string $text, int $length): string
    {
        $kept = [];
        $left = $length;
        foreach (explode("\n", $text) as $line) {
            $line = self::clean($line, max(1, $left));
            if ($line !== '' && $left > 0) {
                $kept[] = $line;
                $left -= mb_strlen($line) + 1;
            }
        }
        return implode("\n", $kept);
    }

    public static function clean(string $text, int $length): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        $text = (string)preg_replace('/[^\s@]+@[^\s@]+/u', '[address]', $text);
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }
}
