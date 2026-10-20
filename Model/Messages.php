<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The messages, Slack mrkdwn without emoji: a bold first line that says what and where (what a phone notification
 * shows), a blank line, one fact per line, a blank line and what to do. The summary's sections are bold headings with
 * "• " lines, empty ones left out. Times in the recipient's time zone. Built only from the shop's name, check labels,
 * titles, hints, statuses, data-free details, counts and the log errors' safe labels:
 *
 *   *Problem: Cron stopped · Main Website Store (all websites)*
 *
 *   No job has run since 10:39 (65 min).
 *
 *   What to do: Is the cron container (or crontab) running? ...
 */
class Messages
{
    /** The word in front of an alert's title. */
    public const WORDS = [CheckResult::FAIL => 'Problem', CheckResult::WARN => 'Warning'];
    /** Checks with a section of their own in the summary (not in "All OK", nor sensitive_data: notices only). */
    private const LOGS = ['system_log', 'exceptions'];
    /** A check's notices with a title of their own (the high alert for sensitive data in logs). */
    private const NOTICE_TITLES = ['sensitive_data' => 'Security: sensitive data in logs'];
    /** What a check's notices are, in "New <noun> in <label>" (else "error"): a server's line is a problem. */
    private const NOTICE_NOUNS = ['external' => 'problem'];
    /** Notices shown per message (folded ones can add up), then "…and N more". */
    private const NOTICES = 10;
    /** Container rows in a status or summary, all servers together. */
    public const CONTAINERS = 20;
    /** A container's name in a table is cut to this. */
    private const NAME_WIDTH = 28;
    /** Causes shown per alert. */
    private const CAUSES = 4;
    /** The summary's log errors per level, the latest first. */
    private const LIMITS = ['CRITICAL' => 5, 'ERROR' => 10, 'WARNING' => 3];

    public function __construct(
        private readonly CheckPool $pool,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * One change of one check: a problem (or a fail still going on), or its recovery.
     *
     * @param array<mixed> $check the check's entry in the "checks" row
     * @param string $change AlertPlan::PROBLEM, REPEAT or RECOVERED
     */
    public function alert(Target $target, string $code, array $check, string $change): string
    {
        $label = $this->pool->getLabel($code);
        $status = (string)($check['status'] ?? CheckResult::OK);
        if ($change === AlertPlan::RECOVERED || $status === CheckResult::OK) {
            $since = (int)($check['since'] ?? 0);
            return $this->block('*Resolved: ' . $label . ' · ' . $this->where($target) . '*',
                $since > 0 ? ['OK again since ' . $this->when($target, $since) . '.'] : []);
        }
        $title = (string)($check['title'] ?? '') !== '' ? (string)$check['title'] : $label;
        $facts = [];
        $causes = array_filter((array)($check['causes'] ?? []), fn ($cause) => is_array($cause) && isset($cause[1]));
        if ($causes === []) {
            $facts[] = $this->sentence($this->times($target, (string)($check['detail'] ?? '')));
        }
        $hints = [];
        foreach (array_slice($causes, 0, self::CAUSES) as $cause) {
            $facts[] = $this->sentence($this->times($target, (string)$cause[1]));
            if ((string)($cause[2] ?? '') !== '') {
                $hints[(string)$cause[2]] = true;
            }
        }
        if ($change === AlertPlan::REPEAT && (int)($check['since'] ?? 0) > 0) {
            $facts[] = 'Still failing since ' . $this->when($target, (int)$check['since']) . '.';
        }
        return $this->block('*' . self::WORDS[$status] . ': ' . $title . ' · ' . $this->where($target) . '*', $facts,
            array_slice(array_keys($hints), 0, 2));
    }

    /**
     * One check's new notices (e.g. new errors in its logs; each of one or more lines, those of several lines apart
     * by a blank line), then what to do (the "Check: ..." notices, each once).
     *
     * @param string[] $notices
     */
    public function notices(Target $target, string $code, array $notices): string
    {
        $facts = [];
        $hints = [];
        foreach ($notices as $notice) {
            $notice = $this->times($target, $notice);
            if (str_starts_with($notice, 'Check: ')) {
                $hints[] = substr($notice, 7);
            } else {
                $facts[] = $notice;
            }
        }
        $more = array_filter($facts, fn (string $fact) => str_starts_with($fact, '…'));
        $facts = array_values(array_diff_key($facts, $more));
        $errors = count($facts);
        if ($errors > self::NOTICES || $more !== []) {
            $extra = $errors - min($errors, self::NOTICES) + array_sum(array_map(fn (string $fact) => preg_match(
                '/\d+/', $fact, $m) ? (int)$m[0] : 0, $more));
            $facts = array_slice($facts, 0, self::NOTICES);
            if ($extra > 0) {
                $facts[] = '…and ' . $extra . ' more (see the daily summary)';
            }
        }
        $noun = self::NOTICE_NOUNS[$code] ?? 'error';
        $title = self::NOTICE_TITLES[$code]
            ?? 'New ' . $noun . ($errors > 1 ? 's' : '') . ' in ' . $this->pool->getLabel($code);
        $several = array_filter($facts, fn (string $fact) => str_contains($fact, "\n")) !== [];
        return $this->block('*' . $title . ' · ' . $this->where($target) . '*',
            $facts !== [] ? [implode($several ? "\n\n" : "\n", $facts)] : [],
            array_values(array_unique($hints)));
    }

    /**
     * Needs attention (not ok now, then had problems today), the day's log errors, email, the servers' containers, then
     * what was ok all day.
     *
     * @param array<string, array<mixed>> $checks the shop-wide checks and the website's own (the "checks" row)
     * @param array<int|string, array<mixed>> $tallies the day's hours: code => ['w' => worst rank, 'c' => counts]
     * @param array<string, array<mixed>> $fingerprints the "fingerprints" row
     * @param string[] $security the day's sensitive-data findings, one line each (the global summary)
     * @param string $containers containers() of the servers' last statuses (the global summary)
     */
    public function summary(Target $target, array $checks, array $tallies, int $now, array $fingerprints = [],
        array $security = [], string $containers = ''): string
    {
        [$worst, $counts] = $this->sum($tallies);
        $problems = [];
        $hadProblems = [];
        $okAllDay = [];
        foreach ($checks as $code => $check) {
            if (!empty($check['idle'])) {
                continue; // nothing to check yet (e.g. no server has reported): not "OK all day"
            }
            $label = $this->label((string)$code);
            $status = (string)($check['status'] ?? CheckResult::OK);
            if ($status !== CheckResult::OK) {
                $detail = $this->times($target, (string)($check['detail'] ?? ''));
                $problems[] = $label . ': ' . ($detail !== '' ? $detail
                    : ($status === CheckResult::FAIL ? 'failing' : 'warning'));
            } elseif (($worst[$code] ?? 0) > 0) {
                $hadProblems[] = $label . ': ' . ($worst[$code] > 1 ? 'failed' : 'warned') . ' earlier, OK now';
            } elseif (!in_array($this->base((string)$code), array_merge(self::LOGS, ['sales_emails', 'sensitive_data']),
                true)) {
                $okAllDay[] = $okAllDay === [] ? $label : $this->lower($label);
            }
        }
        $sections = [
            $this->section('*Needs attention*', array_merge($problems, $hadProblems)),
            $this->section('*Security*', array_map(fn (string $line) => $this->times($target, $line), $security)),
            $this->section('*Errors, last 24 h*', $this->logs($target, $counts, $fingerprints, $now)),
            $this->section('*Email*', $this->email($target, $checks, $counts)),
            $containers,
            $this->section('*All OK*', $okAllDay !== [] ? [implode(', ', $okAllDay)] : []),
        ];
        return '*' . $this->where($target) . ' · daily summary · ' . $this->local($target, $now, 'j M') . '*'
            . implode('', array_map(fn (string $section) => "\n\n" . $section, array_filter($sections)));
    }

    /**
     * The hourly status: "2 problems: Cron, Indexers", "Warnings: Queue (backlog 120)", "Still open: Caches" (told
     * before) - else "All checks OK" - then "New errors this hour: N" with a line per error ("• Unable to send (3×,
     * new)") when there were any.
     *
     * @param string[] $problems the labels of the checks failing
     * @param list<array{0: string, 1: string}> $warnings the label and detail of the checks warning
     * @param list<array{0: string, 1: int, 2: bool}> $errors label, count, first seen since the last status
     * @param list<array{0: string, 1: string}> $idle the label and detail of the checks with nothing to check yet
     * @param string $heading "Hourly status", or "Status now" for the admin's button
     * @param string[] $known the labels of checks still not OK that a posted status told before
     */
    public function status(Target $target, array $problems, array $warnings, int $errorCount, array $errors,
        int $moreErrors, array $idle = [], string $heading = 'Hourly status', array $known = []): string
    {
        $lines = [];
        if ($problems !== []) {
            $lines[] = Format::many(count($problems), 'problems') . ': ' . implode(', ', $problems);
        }
        if ($warnings !== []) {
            $lines[] = 'Warnings: ' . implode(', ', array_map(function (array $warning) use ($target): string {
                $detail = CheckResult::clean($this->times($target, $warning[1]), 60);
                return $warning[0] . ($detail !== '' ? ' (' . $detail . ')' : '');
            }, $warnings));
        }
        if ($known !== []) {
            $lines[] = 'Still open: ' . implode(', ', $known); // told before: the daily summary has them
        }
        foreach ($idle as [$label, $detail]) {
            $lines[] = $label . ': ' . CheckResult::clean($detail, 80); // "Server status: not set up (...)"
        }
        if ($problems === [] && $warnings === [] && $known === []) {
            array_unshift($lines, 'All checks OK');
        }
        $text = '*' . $heading . ' · ' . $this->where($target) . "*\n\n" . implode("\n", $lines);
        if ($errorCount > 0) {
            $text .= "\n\nNew errors this hour: " . Format::number($errorCount);
            foreach ($errors as [$label, $count, $new]) {
                $text .= "\n• " . $label . ' (' . Format::number($count) . '×' . ($new ? ', new' : '') . ')';
            }
            $text .= $moreErrors > 0 ? "\n• …and " . $moreErrors . ' more' : '';
        }
        return $text;
    }

    /**
     * The servers' containers as a table, one per server (a code block, so the columns line up in Slack):
     * NAME, CPU, MEM (used / limit), MEM% and RESTARTS of each status line with measurements ("metrics"), the
     * lines of a server without a fresh status left out. At most CONTAINERS rows in all, then "…and N more"; '' when
     * there are none.
     *
     * @param array<string, array<mixed>> $servers the "external" state row: source => its last good status
     */
    public function containers(array $servers, int $now, int $staleAfter): string
    {
        $blocks = [];
        $left = self::CONTAINERS;
        $more = 0;
        ksort($servers);
        foreach ($servers as $source => $server) {
            if ((int)($server['generated_at'] ?? 0) <= $now - $staleAfter) {
                continue;
            }
            $rows = [];
            foreach ((array)($server['checks'] ?? []) as $line) {
                $metrics = (array)($line['metrics'] ?? []);
                if ($metrics === []) {
                    continue;
                }
                if ($left <= 0) {
                    $more++;
                    continue;
                }
                $left--;
                $used = $metrics['mem_used_bytes'] ?? null;
                $limit = $metrics['mem_limit_bytes'] ?? null;
                $rows[] = [
                    (string)preg_replace('/^Container /', '', (string)($line['label'] ?? $line['code'] ?? '')),
                    isset($metrics['cpu_percent']) ? number_format((float)$metrics['cpu_percent'], 1) . '%' : '-',
                    $used !== null ? self::size((int)$used) . ($limit ? ' / ' . self::size((int)$limit) : '') : '-',
                    $used !== null && $limit ? (string)round($used * 100 / $limit) . '%' : '-',
                    isset($metrics['restarts']) ? (string)$metrics['restarts'] : '-',
                ];
            }
            if ($rows !== []) {
                $blocks[] = '*Containers · ' . $source . "*\n```\n" . self::table(['NAME', 'CPU', 'MEM', 'MEM%',
                    'RESTARTS'], $rows) . "\n```";
            }
        }
        if ($more > 0) {
            $blocks[] = '…and ' . $more . ' more';
        }
        return implode("\n", $blocks);
    }

    /**
     * Columns padded to their widest cell: the first left, the rest right-aligned.
     *
     * @param string[] $header
     * @param list<string[]> $rows
     */
    private static function table(array $header, array $rows): string
    {
        $rows = array_merge([$header], array_map(fn (array $row) => array_map(
            fn (string $cell) => mb_substr($cell, 0, self::NAME_WIDTH), $row), $rows));
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }
        return implode("\n", array_map(fn (array $row) => rtrim(implode('  ', array_map(
            fn (string $cell, int $i) => $i === 0 ? $cell . str_repeat(' ', $widths[$i] - mb_strlen($cell))
                : str_repeat(' ', $widths[$i] - mb_strlen($cell)) . $cell, $row, array_keys($row)))), $rows));
    }

    /** "512M", "1.5G", "980K", "12B": short, for a table. */
    private static function size(int $bytes): string
    {
        foreach (['T' => 1 << 40, 'G' => 1 << 30, 'M' => 1 << 20, 'K' => 1 << 10] as $unit => $size) {
            if ($bytes >= $size) {
                $value = $bytes / $size;
                return ($value < 10 ? rtrim(rtrim(number_format($value, 1), '0'), '.') : (string)round($value)) . $unit;
            }
        }
        return $bytes . 'B';
    }

    public function test(Target $target): string
    {
        return $this->block('*Test alert · ' . $this->where($target) . '*', ['This channel gets the shared checks\''
            . ' alerts (cron, indexers, caches, logs, ...), security findings, the hourly status and the daily'
            . ' summary.']);
    }

    /**
     * The logs' errors of the day: Critical, then Error, then Warning (at most LIMITS each, the latest first), one line
     * per fingerprint (Fingerprints::line()).
     *
     * @param array<string, array<string, int>> $counts by check code
     * @param array<string, array<mixed>> $fingerprints
     * @return string[]
     */
    private function logs(Target $target, array $counts, array $fingerprints, int $now): array
    {
        $byFingerprint = [];
        foreach (self::LOGS as $code) {
            foreach ($counts[$code] ?? [] as $key => $count) {
                if (str_starts_with((string)$key, 'fp:')) {
                    $byFingerprint[substr((string)$key, 3)] = ($byFingerprint[substr((string)$key, 3)] ?? 0) + $count;
                }
            }
        }
        $groups = [];
        foreach ($byFingerprint as $fp => $count) {
            $known = $fingerprints[$fp] ?? null;
            if (is_array($known) && $count > 0) {
                $groups[(string)($known['level'] ?? 'ERROR')][$fp] = $known + ['count24' => $count];
            }
        }
        $lines = [];
        foreach (self::LIMITS as $level => $limit) {
            $entries = $groups[$level] ?? [];
            uasort($entries, fn ($a, $b) => (int)($b['last'] ?? 0) <=> (int)($a['last'] ?? 0));
            foreach (array_slice($entries, 0, $limit, true) as $known) {
                $lines[] = Fingerprints::LEVELS[$level] . ': ' . $this->times($target, Fingerprints::line($known,
                    (int)$known['count24'], (int)($known['first'] ?? 0) > $now - 86400));
            }
            if (count($entries) > $limit) {
                $lines[] = '…and ' . (count($entries) - $limit) . ' more';
            }
        }
        return $lines;
    }

    /**
     * "All order emails sent, no mail errors", else a line each: "2 order emails not sent, oldest 09:12" (now) and
     * "3 mail errors, last 09:40" (the day).
     *
     * @param array<string, array<mixed>> $checks
     * @param array<string, array<string, int>> $counts by check code
     * @return string[]
     */
    private function email(Target $target, array $checks, array $counts): array
    {
        $lines = [];
        $sales = array_filter($checks, fn ($code) => $this->base((string)$code) === 'sales_emails',
            ARRAY_FILTER_USE_KEY);
        foreach ($sales as $code => $check) {
            $gauges = (array)($check['gauges'] ?? []);
            $unsent = (int)($gauges['unsent'] ?? 0);
            $site = $this->site((string)$code);
            if ($unsent > 0) {
                $lines[] = ($site !== '' ? $site . ': ' : '') . Format::many($unsent, 'order emails') . ' not sent'
                    . ((int)($gauges['oldest'] ?? 0) > 0 ? ', oldest ' . $this->when($target, (int)$gauges['oldest'])
                    : '');
            }
        }
        if ($sales !== [] && $lines === []) {
            $lines[] = 'All order emails sent';
        }
        if (isset($checks['email_failing'])) {
            $errors = (int)($counts['email_failing']['mail errors'] ?? 0);
            $last = (int)($checks['email_failing']['gauges']['last error'] ?? 0);
            $lines[] = $errors === 0 ? 'No mail errors' : Format::many($errors, 'mail errors')
                . ($last > 0 ? ', last ' . $this->when($target, $last) : '');
        }
        return $lines === ['All order emails sent', 'No mail errors'] ? ['All order emails sent, no mail errors']
            : $lines;
    }

    /**
     * The header line, a blank line, the facts one per line, a blank line and what to do.
     *
     * @param string[] $facts
     * @param string[] $hints
     */
    private function block(string $header, array $facts, array $hints = []): string
    {
        $text = $header;
        foreach ([$facts, array_map(fn (string $hint) => 'What to do: ' . $hint, array_filter($hints))] as $lines) {
            $lines = array_filter($lines, fn (string $line) => $line !== '');
            $text .= $lines !== [] ? "\n\n" . implode("\n", $lines) : '';
        }
        return $text;
    }

    /**
     * A summary section: its bold heading on its own line, "• " lines below it; '' without lines.
     *
     * @param string[] $lines
     */
    private function section(string $heading, array $lines): string
    {
        return $lines !== [] ? $heading . "\n• " . implode("\n• ", $lines) : '';
    }

    /** "Email sending" in a list after the first: "email sending" (not a name like "RabbitMQ"). */
    private function lower(string $label): string
    {
        return preg_match('/^\p{Lu}\p{Ll}/u', $label) ? mb_strtolower(mb_substr($label, 0, 1)) . mb_substr($label, 1)
            : $label;
    }

    /**
     * @param array<int|string, array<mixed>> $tallies
     * @return array{0: array<string, int>, 1: array<string, array<string, int>>} worst rank and counts by check code
     */
    private function sum(array $tallies): array
    {
        $worst = [];
        $counts = [];
        foreach ($tallies as $tally) {
            foreach ($tally as $code => $hour) {
                $worst[$code] = max($worst[$code] ?? 0, (int)($hour['w'] ?? 0));
                foreach ((array)($hour['c'] ?? []) as $key => $count) {
                    $counts[$code][$key] = ($counts[$code][$key] ?? 0) + (int)$count;
                }
            }
        }
        return [$worst, $counts];
    }

    /** The shop's name (the global messages) or the website's (a website's own). */
    private function where(Target $target): string
    {
        return $target->name;
    }

    /** A check's label; "code@<website>" (a website's check in the global summary): "Email sending (EU Store)". */
    private function label(string $code): string
    {
        $site = $this->site($code);
        return $this->pool->getLabel($this->base($code)) . ($site !== '' ? ' (' . $site . ')' : '');
    }

    private function base(string $code): string
    {
        return explode('@', $code, 2)[0];
    }

    private function site(string $code): string
    {
        return explode('@', $code, 2)[1] ?? '';
    }

    /** "no job has run since {t:...}" with the time in the recipient's zone. */
    private function times(Target $target, string $detail): string
    {
        return (string)preg_replace_callback('/\{t:(\d+)\}/', fn (array $m) => $this->when($target, (int)$m[1]),
            $detail);
    }

    /** "No job has run since 10:39 (65 min)." */
    private function sentence(string $detail): string
    {
        if ($detail === '') {
            return '';
        }
        if (preg_match('/^\p{Ll}+(\s|$)/u', $detail)) {
            $detail = mb_strtoupper(mb_substr($detail, 0, 1)) . mb_substr($detail, 1); // not a code (cron_job, a.b)
        }
        return preg_match('/[.!?]$/u', $detail) ? $detail : $detail . '.';
    }

    /** "14:05" today in the recipient's time zone, else "19 Oct 14:05". */
    private function when(Target $target, int $time): string
    {
        $today = $this->local($target, $this->dateTime->gmtTimestamp(), 'Y-m-d')
            === $this->local($target, $time, 'Y-m-d');
        return $this->local($target, $time, $today ? 'H:i' : 'j M H:i');
    }

    private function local(Target $target, int $time, string $format): string
    {
        try {
            $zone = new \DateTimeZone($target->timezone !== '' ? $target->timezone : 'UTC');
        } catch (\Exception) {
            $zone = new \DateTimeZone('UTC');
        }
        return (new \DateTimeImmutable('@' . $time))->setTimezone($zone)->format($format);
    }
}
