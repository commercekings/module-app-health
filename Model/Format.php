<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

/** Short wording for the messages: durations, numbers and "name xN" lists. */
class Format
{
    /** "12 min", "2 h", "3 days" */
    public static function duration(int $seconds): string
    {
        $minutes = max(1, intdiv(max(0, $seconds), 60));
        return match (true) {
            $minutes < 120 => $minutes . ' min',
            $minutes < 2880 => intdiv($minutes, 60) . ' h',
            default => intdiv($minutes, 1440) . ' days',
        };
    }

    public static function number(int $number): string
    {
        return number_format($number);
    }

    /**
     * "sitemap_generate x2, backup x1" - the largest first, at most $limit, "+N more" for the rest.
     *
     * @param array<string, int> $counts
     */
    public static function top(array $counts, int $limit = 3): string
    {
        arsort($counts);
        $parts = [];
        foreach (array_slice($counts, 0, $limit, true) as $name => $count) {
            $parts[] = $name . ' x' . self::number($count);
        }
        $more = count($counts) - $limit;
        return implode(', ', $parts) . ($more > 0 ? ', +' . $more . ' more' : '');
    }

    /**
     * "1,437 runs, 2 errors (sitemap_generate x2)" from CheckResult counts.
     *
     * @param array<string, int> $counts
     */
    public static function counts(array $counts): string
    {
        $totals = [];
        $items = [];
        foreach ($counts as $key => $count) {
            [$total, $item] = array_pad(explode(':', (string)$key, 2), 2, null);
            if ($item === null) {
                $totals[$total] = $count;
            } else {
                $items[$total][$item] = $count;
                $totals[$total] ??= null;
            }
        }
        $parts = [];
        foreach ($totals as $total => $count) {
            $count ??= array_sum($items[$total] ?? []);
            $parts[] = self::number($count) . ' ' . $total
                . (!empty($items[$total]) ? ' (' . self::top($items[$total]) . ')' : '');
        }
        return implode(', ', $parts);
    }

    /** "a, b and c" */
    public static function list(array $names): string
    {
        $names = array_values($names);
        return count($names) < 2 ? implode('', $names)
            : implode(', ', array_slice($names, 0, -1)) . ' and ' . $names[count($names) - 1];
    }
}
