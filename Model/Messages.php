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
    /** Notices shown per message (folded ones can add up), then "…and N more". */
    private const NOTICES = 10;
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
            $hints !== [] ? array_slice(array_keys($hints), 0, 2) : [$this->pool->getHint($code)]);
    }

    /**
     * One check's new notices (e.g. new errors in its logs; each of one or more lines, those of several lines apart
     * by a blank line), then what to do ("Check: ..." notices, else the check's hint).
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
        $title = self::NOTICE_TITLES[$code]
            ?? 'New ' . ($errors > 1 ? 'errors' : 'error') . ' in ' . $this->pool->getLabel($code);
        $several = array_filter($facts, fn (string $fact) => str_contains($fact, "\n")) !== [];
        return $this->block('*' . $title . ' · ' . $this->where($target) . '*',
            $facts !== [] ? [implode($several ? "\n\n" : "\n", $facts)] : [],
            $hints !== [] ? $hints : [$this->pool->getHint($code)]);
    }

    /**
     * Needs attention (not ok now, then had problems today), the day's log errors, email, then what was ok all day.
     *
     * @param array<string, array<mixed>> $checks the shop-wide checks and the website's own (the "checks" row)
     * @param array<int|string, array<mixed>> $tallies the day's hours: code => ['w' => worst rank, 'c' => counts]
     * @param array<string, array<mixed>> $fingerprints the "fingerprints" row
     */
    public function summary(Target $target, array $checks, array $tallies, int $now, array $fingerprints = []): string
    {
        [$worst, $counts] = $this->sum($tallies);
        $problems = [];
        $hadProblems = [];
        $okAllDay = [];
        foreach ($checks as $code => $check) {
            $label = $this->pool->getLabel((string)$code);
            $status = (string)($check['status'] ?? CheckResult::OK);
            if ($status !== CheckResult::OK) {
                $detail = $this->times($target, (string)($check['detail'] ?? ''));
                $problems[] = $label . ': ' . ($detail !== '' ? $detail
                    : ($status === CheckResult::FAIL ? 'failing' : 'warning'));
            } elseif (($worst[$code] ?? 0) > 0) {
                $hadProblems[] = $label . ': ' . ($worst[$code] > 1 ? 'failed' : 'warned') . ' earlier, OK now';
            } elseif (!in_array($code, array_merge(self::LOGS, ['sales_emails', 'sensitive_data']), true)) {
                $okAllDay[] = $okAllDay === [] ? $label : $this->lower($label);
            }
        }
        $sections = [
            $this->section('*Needs attention*', array_merge($problems, $hadProblems)),
            $this->section('*Errors, last 24 h*', $this->logs($target, $counts, $fingerprints, $now)),
            $this->section('*Email*', $this->email($target, $checks, $counts)),
            $this->section('*All OK*', $okAllDay !== [] ? [implode(', ', $okAllDay)] : []),
        ];
        return '*' . $this->where($target) . ' · daily summary · ' . $this->local($target, $now, 'j M') . '*'
            . implode('', array_map(fn (string $section) => "\n\n" . $section, array_filter($sections)));
    }

    /**
     * The hourly status: "All checks OK", "2 problems: Cron, Indexers" (or "No problems"), "Warnings: Queue (backlog
     * 120)", then "New errors this hour: N" with a line per error ("• Unable to send (3×, new)") when there were any.
     *
     * @param string[] $problems the labels of the checks failing
     * @param list<array{0: string, 1: string}> $warnings the label and detail of the checks warning
     * @param list<array{0: string, 1: int, 2: bool}> $errors label, count, first seen since the last status
     */
    public function status(Target $target, array $problems, array $warnings, int $errorCount, array $errors,
        int $moreErrors): string
    {
        $text = '*Hourly status · ' . $this->where($target) . "*\n\n" . match (true) {
            $problems !== [] => Format::many(count($problems), 'problems') . ': ' . implode(', ', $problems),
            $warnings !== [] => 'No problems',
            default => 'All checks OK',
        };
        if ($warnings !== []) {
            $text .= "\nWarnings: " . implode(', ', array_map(function (array $warning) use ($target): string {
                $detail = CheckResult::clean($this->times($target, $warning[1]), 60);
                return $warning[0] . ($detail !== '' ? ' (' . $detail . ')' : '');
            }, $warnings));
        }
        if ($errorCount > 0) {
            $text .= "\n\nNew errors this hour: " . Format::number($errorCount);
            foreach ($errors as [$label, $count, $new]) {
                $text .= "\n• " . $label . ' (' . Format::number($count) . '×' . ($new ? ', new' : '') . ')';
            }
            $text .= $moreErrors > 0 ? "\n• …and " . $moreErrors . ' more' : '';
        }
        return $text;
    }

    public function test(Target $target): string
    {
        $gets = $target->isShopWide()
            ? 'This channel gets the alerts of the checks for all websites (cron, indexers, caches, logs, ...).'
            : 'This channel gets this website\'s alerts (its email settings, its sales emails) and its daily summary.';
        return $this->block('*Test alert · ' . $this->where($target) . '*', [$gets]);
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
        if (isset($checks['sales_emails'])) {
            $gauges = (array)($checks['sales_emails']['gauges'] ?? []);
            $unsent = (int)($gauges['unsent'] ?? 0);
            $lines[] = $unsent === 0 ? 'All order emails sent' : Format::many($unsent, 'order emails') . ' not sent'
                . ((int)($gauges['oldest'] ?? 0) > 0 ? ', oldest ' . $this->when($target, (int)$gauges['oldest']) : '');
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

    /** "Main Website Store (all websites)" for the shop-wide checks. */
    private function where(Target $target): string
    {
        return $target->name . ($target->isShopWide() ? ' (all websites)' : '');
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
