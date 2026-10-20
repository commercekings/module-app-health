<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Notification;

use CommerceKings\AppHealth\Model\AlertPlan;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\Notification\Alert;
use CommerceKings\AppHealth\Model\Notification\Summary;
use CommerceKings\AppHealth\Model\Notification\TeamMessage;
use CommerceKings\AppHealth\Model\SummarySchedule;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Pool;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use PHPUnit\Framework\TestCase;

/** What the consumer composes on fresh state - one message per piece of news - and what it records once sent. */
class AlertAndSummaryTest extends TestCase
{
    use Pool;
    use Settings;

    private const NOW = 1792483200; // 08:00 UTC

    private MemoryStore $store;
    /** @var string[] sent texts */
    private array $sent = [];
    private bool $failing = false;

    protected function setUp(): void
    {
        $this->store = (new MemoryStore())->seed(['checks' => [
            0 => ['cron' => ['status' => 'fail', 'title' => 'Cron stopped', 'detail' => 'no job has run since {t:'
                . (self::NOW - 720) . '} (12 min)', 'since' => self::NOW - 600],
                'indexers' => ['status' => 'warn', 'detail' => 'x invalid for 2 h', 'since' => self::NOW],
                'caches' => ['status' => 'ok', 'since' => self::NOW]],
            1 => ['email_disabled' => ['status' => 'ok', 'since' => self::NOW]],
            2 => ['email_disabled' => ['status' => 'fail', 'title' => 'Emails disabled',
                'detail' => 'emails are disabled', 'since' => self::NOW]],
        ], 'tally:' . gmdate('YmdH', self::NOW - 3600) => [0 => ['cron' => ['w' => 2, 'c' => ['runs' => 10]]],
            2 => ['email_disabled' => ['w' => 2, 'c' => []]]],
            'tally:' . gmdate('YmdH', self::NOW - 25 * 3600) => [0 => ['cron' => ['w' => 0, 'c' => ['runs' => 99]]]]]);
    }

    /** Sends what $compose returns, as the real TeamMessage does, while the channel takes it. */
    private function teamMessage(Target $target, array $row = []): TeamMessage
    {
        $teamMessage = $this->createStub(TeamMessage::class);
        $teamMessage->method('deliver')->willReturnCallback(function ($type, $reference, \Closure $compose) use (
            $target,
            $row
        ): bool {
            $composed = $compose($target, $row + ['sent_at' => null]);
            if ($composed === null || $this->failing) {
                return false;
            }
            $this->sent[] = $composed[0];
            ($composed[1])();
            return true;
        });
        return $teamMessage;
    }

    private function messages(): Messages
    {
        return new Messages($this->pool(['cron' => 'Cron', 'indexers' => 'Indexers', 'caches' => 'Caches',
            'system_log' => 'system.log'], ['email_disabled' => 'Email sending']), new Clock(self::NOW));
    }

    private function alert(int $scope): void
    {
        (new Alert($this->teamMessage(new Target($scope, 1, 'slack', 'C' . $scope, 'Shop', 'UTC')), $this->store,
            $this->messages(), $this->config(), new Clock(self::NOW)))->process((string)$scope);
    }

    public function testOneMessagePerProblemEachRecordedOnceAWarningNotAtOnce(): void
    {
        $this->alert(0);
        $this->assertCount(1, $this->sent, 'the indexers\' warning waits for the hourly status');
        $this->assertStringStartsWith('*Problem: Cron stopped · Shop (all websites)*', $this->sent[0]);
        $this->assertSame(['cron' => ['status' => 'fail', 'at' => self::NOW]], $this->store->row('alerted:0'));
        $this->sent = [];
        $this->alert(0);
        $this->assertSame([], $this->sent, 'told already: nothing to send');
    }

    public function testNoticesGoOncePerCheckAfterTheProblems(): void
    {
        $this->store->seed(['alerted:0' => ['cron' => ['status' => 'fail', 'at' => self::NOW],
            'indexers' => ['status' => 'warn', 'at' => self::NOW]],
            AlertPlan::NOTICES . '0' => AlertPlan::addNotices([], ['system_log' => ["Error: a (07:00)\nWhere: a.php:1",
                'Error: b (2×, 07:10)']], self::NOW)]);
        $this->alert(0);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString("*New errors in system.log · Shop (all websites)*\n\n"
            . "Error: a (07:00)\nWhere: a.php:1\n\nError: b (2×, 07:10)\n\nWhat to do: ",
            $this->sent[0]);
        $this->assertSame(['system_log' => ['id' => 2, 'at' => self::NOW]],
            $this->store->row(AlertPlan::NOTICED . '0'));
        $this->sent = [];
        $this->alert(0);
        $this->assertSame([], $this->sent);
    }

    public function testAFailedSendStopsAndKeepsTheNewsForTheNextTry(): void
    {
        $this->failing = true;
        $this->alert(0);
        $this->assertNull($this->store->row('alerted:0'));
        $this->failing = false;
        $this->alert(0);
        $this->assertCount(1, $this->sent);
    }

    public function testAWebsitesAlertCarriesOnlyItsOwnChecks(): void
    {
        $this->alert(2);
        $this->assertSame(["*Problem: Emails disabled · Shop*\n\nEmails are disabled.\n\n"
            . 'What to do: Look at Email sending.'],
            $this->sent);
        $this->sent = [];
        $this->alert(1);
        $this->assertSame([], $this->sent, 'website 1 is fine');
    }

    private function summary(array $settings, array $row = []): void
    {
        $config = $this->config($settings);
        (new Summary($this->teamMessage(new Target(2, 2, 'slack', 'C2', 'Shop', 'UTC'), $row), $this->store,
            $this->messages(), $config, new SummarySchedule($config), new Clock(self::NOW)))->process('2');
    }

    public function testTheSummaryCoversTheShopWideChecksAndTheWebsitesOwnForTheLastDay(): void
    {
        $this->summary(['general/summary' => '1', 'general/summary_time' => '07,30,00']);
        $this->assertCount(1, $this->sent);
        $this->assertStringStartsWith("*Shop · daily summary · 20 Oct*\n\n*Needs attention*\n"
            . "• Cron: no job has run since 07:48 (12 min)\n• Indexers: x invalid for 2 h\n"
            . "• Email sending: emails are disabled\n\n", $this->sent[0]);
        $this->assertStringEndsWith("\n\n*All OK*\n• Caches", $this->sent[0],
            'the tally of 25 hours ago is left out');
    }

    public function testTheSummaryOnlyWhenDueAndOn(): void
    {
        $this->summary(['general/summary' => '1', 'general/summary_time' => '07,30,00'],
            ['sent_at' => gmdate('Y-m-d H:i:s', self::NOW - 600)]);
        $this->assertSame([], $this->sent, 'sent since 07:30');
        $this->summary(['general/summary' => '0', 'general/summary_time' => '07,30,00']);
        $this->assertSame([], $this->sent, 'off');
    }
}
