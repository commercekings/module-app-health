<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\AlertPlan;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Pool;
use PHPUnit\Framework\TestCase;

/** The approved layout: one problem per alert, its first line what and where, status emoji, empty sections out. */
class MessagesTest extends TestCase
{
    use Pool;

    private const NOW = 1792483200; // 2026-10-20 08:00 UTC, 10:00 in Belgrade

    private Messages $messages;

    protected function setUp(): void
    {
        $this->messages = new Messages($this->pool(['cron' => 'Cron', 'indexers' => 'Indexers', 'caches' => 'Caches',
            'system_log' => 'system.log', 'exceptions' => 'exception.log', 'email_failing' => 'Sending'],
            ['email_disabled' => 'Email sending', 'sales_emails' => 'Sales emails']), new Clock(self::NOW));
    }

    private function target(int $websiteId = 0): Target
    {
        return new Target($websiteId, 1, 'slack', 'C0123456789', 'Main Website Store', 'Europe/Belgrade');
    }

    public function testAProblemSaysWhatAndWhereThenWhyAndWhereToLook(): void
    {
        $check = ['status' => 'fail', 'title' => 'Cron stopped', 'since' => self::NOW - 600,
            'detail' => 'no job has run since {t:' . (self::NOW - 3900) . '} (65 min)'];
        $this->assertSame("🔴 *Cron stopped – Main Website Store (all websites)*\n"
            . "No job has run since 08:55 (65 min).\n"
            . 'Check: Look at Cron.', $this->messages->alert($this->target(), 'cron', $check, AlertPlan::PROBLEM));
    }

    public function testCausesSayWhyAndWhereToLook(): void
    {
        $check = ['status' => 'fail', 'title' => 'Cron jobs stuck', 'since' => self::NOW,
            'detail' => 'indexer_reindex_all_invalid running for 3 h', 'causes' => [
                ['cron_job_stuck', 'job indexer_reindex_all_invalid running for 3 h (since {t:' . (self::NOW - 10800)
                    . '})', 'The process may have crashed: restart cron, then clear the stuck job.'],
                ['cron_group_waiting', '12 other jobs in its group are waiting', ''],
            ]];
        $this->assertSame("🔴 *Cron jobs stuck – Main Website Store (all websites)*\n"
            . "> Job indexer_reindex_all_invalid running for 3 h (since 07:00).\n"
            . "> 12 other jobs in its group are waiting.\n"
            . '> Check: The process may have crashed: restart cron, then clear the stuck job.',
            $this->messages->alert($this->target(), 'cron', $check, AlertPlan::PROBLEM));
    }

    public function testAWarningARepeatAndARecovery(): void
    {
        $warn = ['status' => 'warn', 'title' => 'Cache invalidated', 'detail' => 'config invalidated for 2 h',
            'since' => self::NOW - 7200];
        $this->assertStringStartsWith('🟡 *Cache invalidated – Main Website Store*',
            $this->messages->alert($this->target(1), 'caches', $warn, AlertPlan::PROBLEM));
        $fail = ['status' => 'fail', 'detail' => 'catalog_product_price invalid for 7 h',
            'since' => self::NOW - 25 * 3600];
        $this->assertSame("🔴 *Still: Indexers failing – Main Website Store (all websites)*\n"
            . "catalog_product_price invalid for 7 h.\nFailing since 19 Oct 09:00.\nCheck: Look at Indexers.",
            $this->messages->alert($this->target(), 'indexers', $fail, AlertPlan::REPEAT),
            'no title: "<label> failing"');
        $this->assertSame("✅ *Recovered: Cron – Main Website Store (all websites)*\nOK again since 09:52.",
            $this->messages->alert($this->target(), 'cron', ['status' => 'ok', 'since' => self::NOW - 480],
                AlertPlan::RECOVERED));
    }

    public function testNewErrorsOfOneCheckInOneMessage(): void
    {
        $this->assertSame("🔴 *New errors: system.log – Main Website Store (all websites)*\n"
            . "NEW ERROR main: Unable to send *** x3 #a1b2c3d4\nCheck: Look at system.log.",
            $this->messages->notices($this->target(), 'system_log',
                [7 => 'NEW ERROR main: Unable to send *** x3 #a1b2c3d4']));
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
            'aaaaaaaa' => ['label' => 'Unable to send order ***', 'level' => 'ERROR', 'message' => 'Unable to send'
                . ' order *** to ***', 'location' => 'app/code/A/B.php:84', 'first' => self::NOW - 3600,
                'last' => self::NOW - 600],
            'bbbbbbbb' => ['label' => 'Magento\\Framework\\Exception\\NoSuchEntityException', 'level' => 'CRITICAL',
                'message' => 'No such entity with ***', 'first' => 1, 'last' => self::NOW - 7200],
            'cccccccc' => ['label' => 'Payment gateway down', 'level' => 'CRITICAL', 'first' => 1,
                'last' => self::NOW - 60, 'location' => '…/pay/Client.php:88'],
            'dddddddd' => ['label' => 'Slow response ***', 'level' => 'WARNING', 'first' => 1, 'last' => self::NOW],
        ];
        $this->assertSame("*Daily summary – Main Website Store – 20 Oct*\n\n"
            . "*Problems (2)*\n🔴 Cron: no job has run since 09:00 (60 min)\n✅ Caches: warned earlier, ok now\n\n"
            . "*Logs (24 h)*\nsystem.log: 1 critical, 14 errors, 52 warnings\nexception.log: 5\n"
            . "🔴 *Critical*\n"
            . "Payment gateway down ×1 · 09:59 · …/pay/Client.php:88 · #cccccccc\n"
            . "NoSuchEntityException: No such entity with *** ×5 · 08:00 · #bbbbbbbb\n"
            . "🟠 *Errors*\n🆕 Unable to send order *** to *** ×14 · 09:50 · app/code/A/B.php:84 · #aaaaaaaa\n"
            . "🟡 *Warnings*\nSlow response *** ×52 · 10:00 · #dddddddd\n\n"
            . "*Email*\nSales emails unsent: 0 · Mail errors: 0\n\n"
            . "*OK all day*\nIndexers · Sending · Email sending",
            $this->messages->summary($this->target(1), $checks, $tallies, self::NOW, $fingerprints));
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
        $text = $this->messages->summary($this->target(1), ['system_log' => ['status' => 'ok']],
            [['system_log' => ['w' => 0, 'c' => $counts]]], self::NOW, $fingerprints);
        $this->assertStringContainsString("🟡 *Warnings*\nw0 ×1 · 10:00 · #00000000\nw1 ×1", $text,
            'the latest first');
        $this->assertStringContainsString("w2 ×1 · 09:59 · #00000002\n…and 2 more", $text);
    }

    public function testEmptySectionsAreLeftOut(): void
    {
        $this->assertSame("*Daily summary – Main Website Store – 20 Oct*\n\n*OK all day*\nIndexers",
            $this->messages->summary($this->target(1), ['indexers' => ['status' => 'ok']], [], self::NOW));
    }

    public function testTestMessagesSayWhatTheChannelGets(): void
    {
        $this->assertStringContainsString('checks for all websites', $this->messages->test($this->target()));
        $this->assertStringContainsString('this website\'s alerts', $this->messages->test($this->target(2)));
    }
}
