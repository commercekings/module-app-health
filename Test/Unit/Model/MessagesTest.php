<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\AlertPlan;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Pool;
use PHPUnit\Framework\TestCase;

/**
 * The approved layout (Variation B): no emoji; a bold first line that says what and where, a blank line, one fact per
 * line, a blank line and what to do; the summary's sections as bold headings with "• " lines, empty ones left out.
 */
class MessagesTest extends TestCase
{
    use Pool;

    private const NOW = 1792483200; // 2026-10-20 08:00 UTC, 10:00 in Belgrade

    private Messages $messages;

    protected function setUp(): void
    {
        $this->messages = new Messages($this->pool(['cron' => 'Cron', 'indexers' => 'Indexers', 'caches' => 'Caches',
            'system_log' => 'system.log', 'exceptions' => 'exception.log', 'email_failing' => 'Sending',
            'external' => 'Server status'],
            ['email_disabled' => 'Email sending', 'sales_emails' => 'Sales emails']), new Clock(self::NOW));
    }

    private function target(int $websiteId = 0): Target
    {
        return new Target($websiteId, 1, 'slack', 'C0123456789', 'Main Website Store', 'Europe/Belgrade');
    }

    public function testAProblemSaysWhatAndWhereThenWhyAndWhatToDo(): void
    {
        $check = ['status' => 'fail', 'title' => 'Cron stopped', 'since' => self::NOW - 600,
            'detail' => 'no job has run since {t:' . (self::NOW - 3900) . '} (65 min)',
            // what CheckResult::withHint() makes of a problem without causes: the detail as a cause with the hint
            'causes' => [['general', 'no job has run since {t:' . (self::NOW - 3900) . '} (65 min)', 'Look at Cron.']]];
        $this->assertSame("*Problem: Cron stopped · Main Website Store*\n\n"
            . "No job has run since 08:55 (65 min).\n\n"
            . 'What to do: Look at Cron.', $this->messages->alert($this->target(), 'cron', $check, AlertPlan::PROBLEM));
    }

    public function testCausesOneFactPerLine(): void
    {
        $check = ['status' => 'fail', 'title' => 'Cron jobs stuck', 'since' => self::NOW,
            'detail' => 'indexer_reindex_all_invalid running for 3 h', 'causes' => [
                ['cron_job_stuck', 'job indexer_reindex_all_invalid running for 3 h (since {t:' . (self::NOW - 10800)
                    . '})', 'The process may have crashed: restart cron, then clear the stuck job.'],
                ['cron_group_waiting', '12 other jobs in its group are waiting', ''],
            ]];
        $this->assertSame("*Problem: Cron jobs stuck · Main Website Store*\n\n"
            . "Job indexer_reindex_all_invalid running for 3 h (since 07:00).\n"
            . "12 other jobs in its group are waiting.\n\n"
            . 'What to do: The process may have crashed: restart cron, then clear the stuck job.',
            $this->messages->alert($this->target(), 'cron', $check, AlertPlan::PROBLEM));
    }

    public function testAWarningARepeatAndARecovery(): void
    {
        $warn = ['status' => 'warn', 'title' => 'Cache invalidated', 'detail' => 'config invalidated for 2 h',
            'since' => self::NOW - 7200, 'causes' => [['general', 'config invalidated for 2 h', 'Look at Caches.']]];
        $this->assertSame("*Warning: Cache invalidated · Main Website Store*\n\nConfig invalidated for 2 h.\n\n"
            . 'What to do: Look at Caches.', $this->messages->alert($this->target(1), 'caches', $warn,
                AlertPlan::PROBLEM));
        $fail = ['status' => 'fail', 'detail' => 'catalog_product_price invalid for 7 h',
            'since' => self::NOW - 25 * 3600,
            'causes' => [['general', 'catalog_product_price invalid for 7 h', 'Look at Indexers.']]];
        $this->assertSame("*Problem: Indexers · Main Website Store*\n\n"
            . "catalog_product_price invalid for 7 h.\nStill failing since 19 Oct 09:00.\n\n"
            . 'What to do: Look at Indexers.',
            $this->messages->alert($this->target(), 'indexers', $fail, AlertPlan::REPEAT),
            'no title: the label; the same header as the first alert');
        $this->assertSame("*Resolved: Cron · Main Website Store*\n\nOK again since 09:52.",
            $this->messages->alert($this->target(), 'cron', ['status' => 'ok', 'since' => self::NOW - 480],
                AlertPlan::RECOVERED));
    }

    public function testANewError(): void
    {
        $this->assertSame("*New error in system.log · Main Website Store*\n\n"
            . "Error: Unable to send order (09:50)\nWhere: app/code/A/B.php:84\n\nWhat to do: Look at system.log.",
            $this->messages->notices($this->target(), 'system_log',
                [7 => "Error: Unable to send order ({t:" . (self::NOW - 600) . "})\nWhere: app/code/A/B.php:84",
                    'Check: Look at system.log.']));
    }

    public function testSeveralNewErrorsApartByABlankLine(): void
    {
        $this->assertSame("*New errors in exception.log · Main Website Store*\n\n"
            . "Critical: PaymentException (3×, 09:50)\nWhere: app/code/A/B.php:84\nMessage: Card *** declined\n\n"
            . "Critical: NoSuchEntityException (09:55)\n\n"
            . "…and 2 more (see the daily summary)\n\n"
            . 'What to do: Look at exception.log.',
            $this->messages->notices($this->target(), 'exceptions', [
                "Critical: PaymentException (3×, {t:" . (self::NOW - 600) . "})\nWhere: app/code/A/B.php:84\n"
                    . 'Message: Card *** declined',
                'Critical: NoSuchEntityException ({t:' . (self::NOW - 300) . '})',
                '…and 2 more (see the daily summary)',
                'Check: Look at exception.log.',
            ]));
    }

    public function testFoldedNoticesAreCappedWithTheRestCounted(): void
    {
        $notices = array_map(fn ($i) => 'Critical: e' . $i . ' ({t:' . self::NOW . '})', range(1, 12));
        $notices[] = '…and 4 more (see the daily summary)';
        $notices[] = 'Check: Look at system.log.';
        $notices[] = 'Check: Look at system.log.';
        $text = $this->messages->notices($this->target(), 'system_log', $notices);
        $this->assertStringContainsString("Critical: e10 (10:00)\n…and 6 more (see the daily summary)\n\nWhat to do:",
            $text, 'ten, then the two over and the four the check counted');
        $this->assertStringNotContainsString('e11', $text);
        $this->assertSame(1, substr_count($text, 'What to do:'), 'notices of two runs: what to do once');
    }

    public function testTheDailySummary(): void
    {
        $checks = [
            'cron' => ['status' => 'fail', 'detail' => 'no job has run since {t:' . (self::NOW - 3600) . '} (60 min)'],
            'indexers' => ['status' => 'ok'], 'caches' => ['status' => 'ok'],
            'system_log' => ['status' => 'ok'], 'exceptions' => ['status' => 'ok'],
            'email_failing' => ['status' => 'ok'],
            'email_disabled' => ['status' => 'ok'], 'sales_emails' => ['status' => 'ok', 'gauges' => ['unsent' => 0]],
        ];
        $tallies = [
            ['cron' => ['w' => 2, 'c' => ['runs' => 1000]], 'caches' => ['w' => 1, 'c' => []],
                'system_log' => ['w' => 0, 'c' => ['critical' => 1, 'errors' => 9, 'warnings' => 40,
                    'fp:aaaaaaaa' => 9, 'fp:cccccccc' => 1, 'fp:dddddddd' => 40]],
                'exceptions' => ['w' => 0, 'c' => ['exceptions' => 5, 'fp:bbbbbbbb' => 5]]],
            ['system_log' => ['w' => 0, 'c' => ['errors' => 5, 'warnings' => 12, 'fp:aaaaaaaa' => 5,
                'fp:dddddddd' => 12]], 'email_failing' => ['w' => 0, 'c' => ['mail errors' => 0]]],
        ];
        $fingerprints = [
            'aaaaaaaa' => ['label' => 'Unable to send order', 'level' => 'ERROR', 'message' => 'Unable to send'
                . ' order *** to ***', 'location' => 'app/code/A/B.php:84', 'first' => self::NOW - 3600,
                'last' => self::NOW - 600],
            'bbbbbbbb' => ['label' => 'Magento\\Framework\\Exception\\NoSuchEntityException', 'level' => 'CRITICAL',
                'message' => 'No such entity with ***', 'first' => 1, 'last' => self::NOW - 7200],
            'cccccccc' => ['label' => 'Payment gateway down', 'level' => 'CRITICAL', 'first' => 1,
                'last' => self::NOW - 60, 'location' => '…/pay/Client.php:88'],
            'dddddddd' => ['label' => 'Slow response', 'level' => 'WARNING', 'first' => 1, 'last' => self::NOW],
        ];
        $this->assertSame("*Main Website Store · daily summary · 20 Oct*\n\n"
            . "*Needs attention*\n• Cron: no job has run since 09:00 (60 min)\n• Caches: warned earlier, OK now\n\n"
            . "*Errors, last 24 h*\n"
            . "• Critical: Payment gateway down (1×, 09:59)\n"
            . "• Critical: NoSuchEntityException (5×, 08:00)\n"
            . "• Error: Unable to send order (14×, 09:50, new)\n"
            . "• Warning: Slow response (52×, 10:00)\n\n"
            . "*Email*\n• All order emails sent, no mail errors\n\n"
            . "*All OK*\n• Indexers, sending, email sending",
            $this->messages->summary($this->target(1), $checks, $tallies, self::NOW, $fingerprints),
            'no fingerprints, no file paths, no masked messages');
    }

    public function testUnsentEmailsAndMailErrorsALineEach(): void
    {
        $checks = ['sales_emails' => ['status' => 'ok', 'gauges' => ['unsent' => 2, 'oldest' => self::NOW - 2880]],
            'email_failing' => ['status' => 'ok', 'gauges' => ['last error' => self::NOW - 1200]]];
        $tallies = [['email_failing' => ['w' => 0, 'c' => ['mail errors' => 3]]]];
        $this->assertSame("*Main Website Store · daily summary · 20 Oct*\n\n"
            . "*Email*\n• 2 order emails not sent, oldest 09:12\n• 3 mail errors, last 09:40\n\n*All OK*\n• Sending",
            $this->messages->summary($this->target(1), $checks, $tallies, self::NOW));
        $checks['sales_emails']['gauges'] = ['unsent' => 0];
        $this->assertStringContainsString("*Email*\n• All order emails sent\n• 3 mail errors, last 09:40",
            $this->messages->summary($this->target(1), $checks, $tallies, self::NOW));
        $this->assertStringContainsString("*Email*\n• 1 order email not sent, oldest 09:12\n• No mail errors\n",
            $this->messages->summary($this->target(1), ['sales_emails' => ['status' => 'ok', 'gauges' => [
                'unsent' => 1, 'oldest' => self::NOW - 2880]]] + $checks, [], self::NOW));
    }

    public function testEachLevelIsCappedThenCounted(): void
    {
        $fingerprints = [];
        $counts = ['warnings' => 5];
        for ($i = 0; $i < 5; $i++) {
            $fp = sprintf('%08d', $i);
            $fingerprints[$fp] = ['label' => 'w' . $i, 'level' => 'WARNING', 'first' => 1, 'last' => self::NOW - $i];
            $counts['fp:' . $fp] = 1;
        }
        $this->assertSame("*Main Website Store · daily summary · 20 Oct*\n\n*Errors, last 24 h*\n"
            . "• Warning: w0 (1×, 10:00)\n• Warning: w1 (1×, 09:59)\n• Warning: w2 (1×, 09:59)\n• …and 2 more",
            $this->messages->summary($this->target(1), ['system_log' => ['status' => 'ok']],
                [['system_log' => ['w' => 0, 'c' => $counts]]], self::NOW, $fingerprints), 'the latest first');
    }

    public function testEmptySectionsAreLeftOut(): void
    {
        $this->assertSame("*Main Website Store · daily summary · 20 Oct*\n\n*All OK*\n"
            . '• Indexers, caches, email sending',
            $this->messages->summary($this->target(1), ['indexers' => ['status' => 'ok'],
                'caches' => ['status' => 'ok'], 'email_disabled' => ['status' => 'ok'],
                'sensitive_data' => ['status' => 'ok']], [], self::NOW), 'sensitive data: its alerts only');
        $this->assertSame('*Main Website Store · daily summary · 20 Oct*',
            $this->messages->summary($this->target(), [], [], self::NOW));
    }

    public function testAGlobalSummaryNamesEachWebsitesUnsentEmails(): void
    {
        $checks = ['sales_emails@EU Store' => ['status' => 'ok', 'gauges' => ['unsent' => 2,
            'oldest' => self::NOW - 2880]], 'sales_emails@US Store' => ['status' => 'ok', 'gauges' => ['unsent' => 0]],
            'email_disabled@US Store' => ['status' => 'fail', 'detail' => 'emails are disabled in 1 of 2 store views']];
        $this->assertSame("*Main Website Store · daily summary · 20 Oct*\n\n*Needs attention*\n"
            . "• Email sending (US Store): emails are disabled in 1 of 2 store views\n\n"
            . "*Email*\n• EU Store: 2 order emails not sent, oldest 09:12",
            $this->messages->summary($this->target(), $checks, [], self::NOW));
    }

    public function testTheDaysSecurityFindingsRightAfterNeedsAttention(): void
    {
        $this->assertSame("*Main Website Store · daily summary · 20 Oct*\n\n*Needs attention*\n• Cron: stopped\n\n"
            . "*Security*\n• PCI: payment card number in system.log (1×, 09:44)\n\n*All OK*\n• Indexers",
            $this->messages->summary($this->target(), ['cron' => ['status' => 'fail', 'detail' => 'stopped'],
                'indexers' => ['status' => 'ok']], [], self::NOW, [],
                ['PCI: payment card number in system.log (1×, {t:' . (self::NOW - 960) . '})']));
    }

    public function testWithoutANameTheHeaderSaysTheDomain(): void
    {
        $this->assertStringStartsWith('*Hourly status · shop.example.com*', $this->messages->status(new Target(0, 1,
            'slack', 'C0', '', 'UTC', true, 'shop.example.com'), [], [], 0, [], 0));
    }

    public function testResolvedSaysHowLongItFailed(): void
    {
        $this->assertSame("*Resolved: Cron · Main Website Store*\n\nFailed for 2 h; OK again since 09:52.",
            $this->messages->alert($this->target(), 'cron', ['status' => 'ok', 'since' => self::NOW - 480],
                AlertPlan::RECOVERED, self::NOW - 480 - 7200));
    }

    public function testTheHeaderNamesTheShopsDomain(): void
    {
        $target = new Target(0, 1, 'slack', 'C0', 'Main Website Store', 'UTC', true, 'shop.example.com');
        $this->assertStringStartsWith('*Problem: Cron · Main Website Store · shop.example.com*',
            $this->messages->alert($target, 'cron', ['status' => 'fail'], AlertPlan::PROBLEM));
        $this->assertStringStartsWith('*Hourly status · Main Website Store · shop.example.com*',
            $this->messages->status($target, [], [], 0, [], 0));
    }

    public function testCronBackAfterAGap(): void
    {
        $this->assertSame("*Cron was down · Main Website Store*\n\nCron was down from 02:30 to 10:00 (7 h); it runs"
            . " again.\n\nWhat to do: Look at the server's logs.", $this->messages->notices($this->target(), 'cron', [
                'Cron was down from {t:' . (self::NOW - 27000) . '} to {t:' . self::NOW . '} (7 h); it runs again.',
                "Check: Look at the server's logs."]));
    }

    public function testAServersNewProblemIsAProblemNotAnError(): void
    {
        $this->assertStringStartsWith('*New problem in Server status · Main Website Store*',
            $this->messages->notices($this->target(), 'external', ['Also failing: host-1 · Disk: 95 %']));
    }

    public function testAnIdleCheckIsNotOkAllDayAndTheStatusSaysWhy(): void
    {
        $this->assertSame("*Main Website Store · daily summary · 20 Oct*\n\n*All OK*\n• Indexers",
            $this->messages->summary($this->target(), ['indexers' => ['status' => 'ok'],
                'external' => ['status' => 'ok', 'idle' => true,
                    'detail' => 'not set up (no status file from a server yet)']], [], self::NOW));
        $this->assertSame("*Hourly status · Main Website Store*\n\nAll checks OK\n"
            . 'Server status: not set up (no status file from a server yet)', $this->messages->status($this->target(),
                [], [], 0, [], 0, [['Server status', 'not set up (no status file from a server yet)']]));
    }

    public function testTestMessagesSayWhatTheChannelGets(): void
    {
        $this->assertStringStartsWith("*Test alert · Main Website Store*\n\nThis channel gets the shared"
            . ' checks\' alerts', $this->messages->test($this->target()));
    }

    public function testNoEmojiAnywhere(): void
    {
        $texts = [$this->messages->test($this->target()),
            $this->messages->alert($this->target(), 'cron', ['status' => 'fail', 'detail' => 'x'], AlertPlan::PROBLEM),
            $this->messages->alert($this->target(), 'cron', ['status' => 'warn'], AlertPlan::REPEAT),
            $this->messages->alert($this->target(), 'cron', ['status' => 'ok'], AlertPlan::RECOVERED),
            $this->messages->notices($this->target(), 'sensitive_data', ['PCI: x (1×, last 09:00)']),
            $this->messages->summary($this->target(1), ['cron' => ['status' => 'fail'],
                'caches' => ['status' => 'warn'], 'indexers' => ['status' => 'ok']], [], self::NOW)];
        foreach ($texts as $text) {
            $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text, $text);
        }
        $this->assertStringStartsWith('*Security: sensitive data in logs · Main Website Store*',
            $texts[4]);
    }

    public function testContainersAsATablePerServerCappedAndFresh(): void
    {
        $line = fn (string $name, array $metrics) => ['code' => 'container_' . $name, 'label' => 'Container ' . $name,
            'status' => 'ok', 'metrics' => $metrics];
        $servers = [
            'web-2' => ['generated_at' => self::NOW - 60, 'checks' => [
                $line('nginx', ['cpu_percent' => 0.5, 'mem_used_bytes' => 25165824, 'restarts' => 3]),
                ['code' => 'disk_root', 'label' => 'Disk /', 'status' => 'ok', 'value' => 40],
            ]],
            'db-1' => ['generated_at' => self::NOW - 60, 'checks' => [
                $line('mariadb', ['cpu_percent' => 105.25, 'mem_used_bytes' => 3006477107,
                    'mem_limit_bytes' => 4294967296, 'restarts' => 0]),
            ]],
            'old' => ['generated_at' => self::NOW - 3600, 'checks' => [$line('php', ['cpu_percent' => 1.0])]],
        ];
        $this->assertSame("*Containers · db-1*\n```\n"
            . "NAME        CPU        MEM  MEM%  RESTARTS\n"
            . "mariadb  105.3%  2.8G / 4G   70%         0\n```\n"
            . "*Containers · web-2*\n```\n"
            . "NAME    CPU  MEM  MEM%  RESTARTS\n"
            . "nginx  0.5%  24M     -         3\n```",
            $this->messages->containers($servers, self::NOW, 900),
            'by server; no limit: no %; a stale server left out');
        $many = ['big' => ['generated_at' => self::NOW, 'checks' => array_map(fn ($i) => $line('c' . $i,
            ['cpu_percent' => 1.0]), range(1, Messages::CONTAINERS + 4))]];
        $text = $this->messages->containers($many, self::NOW, 900);
        $this->assertSame(Messages::CONTAINERS, preg_match_all('/^c\d+ /m', $text), 'twenty rows');
        $this->assertStringEndsWith("```\n…and 4 more", $text);
        $this->assertSame('', $this->messages->containers(['x' => ['generated_at' => self::NOW, 'checks' => [
            ['code' => 'disk_root', 'status' => 'ok']]]], self::NOW, 900), 'no measurements: no table');
    }
}
