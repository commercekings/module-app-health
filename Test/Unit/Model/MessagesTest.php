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
                'system_log' => ['w' => 0, 'c' => ['critical' => 0, 'errors' => 9, 'warnings' => 40,
                    'fp:aaaaaaaa' => 9]],
                'exceptions' => ['w' => 0, 'c' => ['exceptions' => 5, 'fp:bbbbbbbb' => 5]]],
            ['system_log' => ['w' => 0, 'c' => ['errors' => 5, 'warnings' => 12, 'fp:aaaaaaaa' => 5]],
                'email_failing' => ['w' => 0, 'c' => ['mail errors' => 0]]],
        ];
        $fingerprints = ['aaaaaaaa' => ['label' => 'Vendor\\Payment\\PaymentException', 'first' => self::NOW - 3600],
            'bbbbbbbb' => ['label' => 'Magento\\Framework\\Exception\\NoSuchEntityException', 'first' => 1]];
        $this->assertSame("*Daily summary – Main Website Store – 20 Oct*\n\n"
            . "*Problems (2)*\n🔴 Cron: no job has run since 09:00 (60 min)\n✅ Caches: warned earlier, ok now\n\n"
            . "*Logs (24 h)*\nsystem.log: 0 critical, 14 errors, 52 warnings\n"
            . "Top errors: PaymentException ×14 (new), NoSuchEntityException ×5\nexception.log: 5\n\n"
            . "*Email*\nSales emails unsent: 0 · Mail errors: 0\n\n"
            . "*OK all day*\nIndexers · Sending · Email sending",
            $this->messages->summary($this->target(1), $checks, $tallies, self::NOW, $fingerprints));
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
