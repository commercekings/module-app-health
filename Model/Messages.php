<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The messages, plain text with Slack's *bold* and "> " quotes: one problem per alert, its first line what and where
 * (what a phone notification shows), a status emoji (🔴 fail, 🟡 warning, ✅ ok or recovered), then a blank line, one
 * fact per quote line, a blank line and where to look. Times in the recipient's time zone, empty sections left out.
 * Built only from the shop's name, check labels, titles, hints, statuses, data-free details, counts and the
 * fingerprints' safe labels:
 *
 *   🔴 *Cron stopped – Main Website Store (all websites)*
 *
 *   > No job has run since 10:39 (65 min).
 *
 *   > Check: Is the cron container (or crontab) running? ...
 */
class Messages
{
    public const EMOJI = [CheckResult::FAIL => '🔴', CheckResult::WARN => '🟡', CheckResult::OK => '✅'];
    /** Checks with a section of their own in the summary (their counts there, not in "OK all day"). */
    private const LOGS = ['system_log', 'exceptions'];
    private const EMAIL = ['sales_emails', 'email_failing'];
    /** A check's notices with a title of their own (the high alert for sensitive data in logs). */
    private const NOTICE_TITLES = ['sensitive_data' => '🚨 *Sensitive data in logs'];
    /** Causes shown per alert. */
    private const CAUSES = 4;
    /** The summary's log entries per level: its title and how many. */
    private const LIMITS = ['CRITICAL' => ['Critical', 5], 'ERROR' => ['Errors', 10], 'WARNING' => ['Warnings', 3]];
    /** Checks whose counts the summary leaves out. */
    private const QUIET = ['cron'];

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
            return $this->block('✅ *Recovered: ' . $label . ' – ' . $this->where($target) . '*',
                $since > 0 ? ['OK again since ' . $this->when($target, $since) . '.'] : []);
        }
        $title = (string)($check['title'] ?? '') !== '' ? (string)$check['title']
            : $label . ($status === CheckResult::FAIL ? ' failing' : ' warning');
        $header = self::EMOJI[$status] . ' *' . ($change === AlertPlan::REPEAT ? 'Still: ' : '') . $title . ' – '
            . $this->where($target) . '*';
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
            $facts[] = 'Failing since ' . $this->when($target, (int)$check['since']) . '.';
        }
        return $this->block($header, $facts,
            $hints !== [] ? array_slice(array_keys($hints), 0, 2) : [$this->pool->getHint($code)]);
    }

    /**
     * One check's new notices (e.g. new errors in its logs), times in the recipient's zone.
     *
     * @param string[] $notices
     */
    public function notices(Target $target, string $code, array $notices): string
    {
        $header = (self::NOTICE_TITLES[$code] ?? '🔴 *New errors: ' . $this->pool->getLabel($code)) . ' – '
            . $this->where($target) . '*';
        $facts = [];
        $hints = [];
        foreach ($notices as $notice) {
            $line = $this->times($target, (string)preg_replace('/^> /', '', $notice));
            if (str_starts_with($line, 'Check: ')) {
                $hints[] = substr($line, 7);
            } else {
                $facts[] = $line;
            }
        }
        return $this->block($header, $facts, $hints !== [] ? $hints : [$this->pool->getHint($code)]);
    }

    /**
     * Problems first (not ok now, then had problems today), the logs, email, other counts, then what was ok all day.
     *
     * @param array<string, array<mixed>> $checks the shop-wide checks and the website's own (the "checks" row)
     * @param array<int|string, array<mixed>> $tallies the day's hours: code => ['w' => worst rank, 'c' => counts]
     * @param array<string, array<mixed>> $fingerprints the "fingerprints" row
     */
    public function summary(Target $target, array $checks, array $tallies, int $now, array $fingerprints = []): string
    {
        [$worst, $counts] = $this->sum($tallies);
        $sections = [];
        $problems = [];
        $hadProblems = [];
        $okAllDay = [];
        foreach ($checks as $code => $check) {
            $label = $this->pool->getLabel((string)$code);
            $status = (string)($check['status'] ?? CheckResult::OK);
            if ($status !== CheckResult::OK) {
                $detail = $this->times($target, (string)($check['detail'] ?? ''));
                $problems[] = self::EMOJI[$status] . ' ' . $label . ($detail !== '' ? ': ' . $detail : '');
            } elseif (($worst[$code] ?? 0) > 0) {
                $hadProblems[] = '✅ ' . $label . ': ' . ($worst[$code] > 1 ? 'failed' : 'warned')
                    . ' earlier, ok now';
            } elseif (!in_array($code, array_merge(self::LOGS, ['sales_emails']), true)) {
                $okAllDay[] = $label;
            }
        }
        $problems = array_merge($problems, $hadProblems);
        if ($problems !== []) {
            $sections[] = $this->section('*Problems (' . count($problems) . ')*', $problems);
        }
        $sections = array_merge($sections, $this->logs($target, $checks, $counts, $fingerprints, $now));
        $email = [];
        if (isset($checks['sales_emails'])) {
            $unsent = (int)($checks['sales_emails']['gauges']['unsent'] ?? 0);
            $email[] = 'Sales emails unsent: ' . Format::number($unsent);
        }
        if (isset($checks['email_failing'])) {
            $email[] = 'Mail errors: ' . Format::number((int)($counts['email_failing']['mail errors'] ?? 0));
        }
        if ($email !== []) {
            $sections[] = $this->section('*Email*', $email);
        }
        $other = [];
        foreach ($checks as $code => $check) {
            $shown = $this->nonZero($counts[$code] ?? []);
            if ($shown !== [] && !in_array($code, array_merge(self::LOGS, self::EMAIL, self::QUIET), true)) {
                $other[] = $this->pool->getLabel((string)$code) . ': ' . Format::counts($shown, ' · ');
            }
        }
        if ($other !== []) {
            $sections[] = $this->section('*Other (24 h)*', $other);
        }
        if ($okAllDay !== []) {
            $sections[] = $this->section('*OK all day*', [implode(' · ', $okAllDay)]);
        }
        return '*Daily summary – ' . $target->name . ' – ' . $this->local($target, $now, 'j M') . '*'
            . ($sections !== [] ? "\n\n" . implode("\n\n", $sections) : '');
    }

    public function test(Target $target): string
    {
        $gets = $target->isShopWide()
            ? 'This channel gets the alerts of the checks for all websites (cron, indexers, caches, logs, ...).'
            : 'This channel gets this website\'s alerts (its email settings, its sales emails) and its daily summary.';
        return $this->block('✅ *Test alert – ' . $this->where($target) . '*', [$gets]);
    }

    /**
     * The logs' sections: their counts, then their entries of the day under Critical, Errors, Warnings (at most LIMITS
     * each, the latest first), one line per fingerprint (Fingerprints::line()).
     *
     * @param array<string, array<mixed>> $checks
     * @param array<string, array<string, int>> $counts by check code
     * @param array<string, array<mixed>> $fingerprints
     * @return string[] the sections
     */
    private function logs(Target $target, array $checks, array $counts, array $fingerprints, int $now): array
    {
        $lines = [];
        $sections = [];
        $byFingerprint = [];
        foreach (self::LOGS as $code) {
            if (!isset($checks[$code])) {
                continue;
            }
            $c = $counts[$code] ?? [];
            $lines[] = $this->pool->getLabel($code) . ': ' . ($code === 'system_log'
                ? sprintf('%s critical · %s · %s', Format::number((int)($c['critical'] ?? 0)),
                    Format::many((int)($c['errors'] ?? 0), 'errors'),
                    Format::many((int)($c['warnings'] ?? 0), 'warnings'))
                : Format::number((int)($c['exceptions'] ?? 0)));
            foreach ($c as $key => $count) {
                if (str_starts_with((string)$key, 'fp:')) {
                    $byFingerprint[substr((string)$key, 3)] = ($byFingerprint[substr((string)$key, 3)] ?? 0) + $count;
                }
            }
        }
        if ($lines !== []) {
            $sections[] = $this->section('*Logs (24 h)*', $lines);
        }
        $groups = [];
        foreach ($byFingerprint as $fp => $count) {
            $known = $fingerprints[$fp] ?? null;
            if (is_array($known) && $count > 0) {
                $groups[(string)($known['level'] ?? 'ERROR')][$fp] = $known + ['count24' => $count];
            }
        }
        foreach (self::LIMITS as $level => [$title, $limit]) {
            $entries = $groups[$level] ?? [];
            if ($entries === []) {
                continue;
            }
            uasort($entries, fn ($a, $b) => (int)($b['last'] ?? 0) <=> (int)($a['last'] ?? 0));
            $lines = [];
            foreach (array_slice($entries, 0, $limit, true) as $fp => $known) {
                $lines[] = $this->times($target, Fingerprints::line((string)$fp, $known, (int)$known['count24'],
                    (int)($known['first'] ?? 0) > $now - 86400, false));
            }
            if (count($entries) > $limit) {
                $lines[] = '…and ' . (count($entries) - $limit) . ' more';
            }
            $sections[] = $this->section(Fingerprints::EMOJI[$level] . ' *' . $title . '*', $lines);
        }
        return $sections;
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

    /**
     * The header line, a blank line, the facts as quote lines, a blank line and where to look ("> Check: ...").
     *
     * @param string[] $facts
     * @param string[] $hints
     */
    private function block(string $header, array $facts, array $hints = []): string
    {
        $text = $header;
        foreach ([$facts, array_map(fn (string $hint) => 'Check: ' . $hint, array_filter($hints))] as $lines) {
            $lines = array_filter($lines, fn (string $line) => $line !== '');
            $text .= $lines !== [] ? "\n\n" . $this->quote($lines) : '';
        }
        return $text;
    }

    /**
     * A summary section: its bold heading on its own line, its lines quoted below it.
     *
     * @param string[] $lines
     */
    private function section(string $heading, array $lines): string
    {
        return $heading . "\n" . $this->quote($lines);
    }

    /** @param string[] $lines */
    private function quote(array $lines): string
    {
        return '> ' . implode("\n> ", $lines);
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

    /**
     * Totals above 0 and their items.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function nonZero(array $counts): array
    {
        return array_filter($counts, function (int $count, string $key) use ($counts): bool {
            $total = explode(':', $key, 2)[0];
            return $count > 0 && (!array_key_exists($total, $counts) || $counts[$total] > 0) && $total !== 'fp';
        }, ARRAY_FILTER_USE_BOTH);
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
