<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\AlertPlan;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Test\Unit\Stub\Pool;
use PHPUnit\Framework\TestCase;

class MessagesTest extends TestCase
{
    use Pool;

    private const NOW = 1792483200; // 2026-10-20 08:00 UTC, 10:00 in Belgrade

    private Messages $messages;

    protected function setUp(): void
    {
        $this->messages = new Messages($this->pool(['cron' => 'Cron', 'indexers' => 'Indexers', 'caches' => 'Caches',
            'exceptions' => 'Exceptions'], ['email_disabled' => 'Email sending']));
    }

    private function target(int $websiteId = 0): Target
    {
        return new Target($websiteId, 1, 'slack', 'C0123456789', 'Moj Planer', 'Europe/Belgrade');
    }

    public function testAnAlertListsProblemsRecoveriesAndTheRest(): void
    {
        $checks = [
            'cron' => ['status' => 'fail', 'detail' => 'no job has run for 12 min', 'since' => self::NOW - 720],
            'indexers' => ['status' => 'ok', 'detail' => '', 'since' => self::NOW],
            'caches' => ['status' => 'warn', 'detail' => 'config invalidated for 2 h', 'since' => self::NOW],
            'exceptions' => ['status' => 'ok', 'detail' => '', 'since' => self::NOW],
        ];
        $this->assertSame("App health - Moj Planer (all websites)\n"
            . "FAIL Cron: no job has run for 12 min (since 09:48)\n"
            . "WARN Caches: config invalidated for 2 h (since 10:00)\n"
            . "RECOVERED Indexers\n"
            . 'OK Exceptions',
            $this->messages->alert($this->target(), $checks, ['cron' => AlertPlan::PROBLEM,
                'indexers' => AlertPlan::RECOVERED, 'caches' => AlertPlan::PROBLEM]));
    }

    public function testAWebsitesAlertNamesTheShopOnly(): void
    {
        $this->assertSame("App health - Moj Planer\nFAIL Email sending: emails are disabled",
            $this->messages->alert($this->target(1), ['email_disabled' => ['status' => 'fail',
                'detail' => 'emails are disabled']], ['email_disabled' => AlertPlan::PROBLEM]));
    }

    public function testTheDailySummary(): void
    {
        $checks = [
            'cron' => ['status' => 'ok', 'since' => self::NOW], 'indexers' => ['status' => 'ok', 'since' => self::NOW],
            'caches' => ['status' => 'fail', 'detail' => 'full_page disabled', 'since' => self::NOW - 3600],
            'exceptions' => ['status' => 'ok', 'since' => self::NOW],
            'email_disabled' => ['status' => 'ok', 'since' => self::NOW],
        ];
        $tallies = [
            ['cron' => ['w' => 1, 'c' => ['runs' => 1000, 'errors' => 2, 'errors:sitemap_generate' => 2]],
                'exceptions' => ['w' => 0, 'c' => ['exceptions' => 9, 'exceptions:A\\PaymentException' => 9]],
                'indexers' => ['w' => 0, 'c' => []]],
            ['cron' => ['w' => 0, 'c' => ['runs' => 437, 'errors' => 0]],
                'exceptions' => ['w' => 2, 'c' => ['exceptions' => 5, 'exceptions:B\\NoSuchEntityException' => 5]]],
        ];
        $this->assertSame("Daily summary - Moj Planer - 20 Oct\n"
            . "FAIL Caches: full_page disabled (since 09:00)\n"
            . "Cron: 1,437 runs, 2 errors (sitemap_generate x2)\n"
            . "Exceptions: 14 exceptions (A\\PaymentException x9, B\\NoSuchEntityException x5)\n"
            . "Had problems: Cron (warn) and Exceptions (fail)\n"
            . 'OK all day: Indexers and Email sending',
            $this->messages->summary($this->target(1), $checks, $tallies, self::NOW));
    }

    public function testZeroCountsAreLeftOut(): void
    {
        $text = $this->messages->summary($this->target(1), ['exceptions' => ['status' => 'ok']],
            [['exceptions' => ['w' => 0, 'c' => ['exceptions' => 0]]]], self::NOW);
        $this->assertStringNotContainsString('Exceptions:', $text);
    }

    public function testTestMessagesSayWhatTheChannelGets(): void
    {
        $this->assertStringContainsString('checks for all websites', $this->messages->test($this->target()));
        $this->assertStringContainsString('this website\'s alerts', $this->messages->test($this->target(2)));
    }
}
