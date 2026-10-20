<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Notification;

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

/** What the consumer composes on fresh state, and what it records once sent. */
class AlertAndSummaryTest extends TestCase
{
    use Pool;
    use Settings;

    private const NOW = 1792483200; // 08:00 UTC

    private MemoryStore $store;
    /** @var array{0: string, 1: \Closure}|null */
    private ?array $composed = null;

    protected function setUp(): void
    {
        $this->store = (new MemoryStore())->seed(['checks' => [
            0 => ['cron' => ['status' => 'fail', 'detail' => 'no job has run for 12 min', 'since' => self::NOW - 720],
                'caches' => ['status' => 'ok', 'since' => self::NOW]],
            1 => ['email_disabled' => ['status' => 'ok', 'since' => self::NOW]],
            2 => ['email_disabled' => ['status' => 'fail', 'detail' => 'emails are disabled', 'since' => self::NOW]],
        ], 'tally:' . gmdate('YmdH', self::NOW - 3600) => [0 => ['cron' => ['w' => 2, 'c' => ['runs' => 10]]],
            2 => ['email_disabled' => ['w' => 2, 'c' => []]]],
            'tally:' . gmdate('YmdH', self::NOW - 25 * 3600) => [0 => ['cron' => ['w' => 0, 'c' => ['runs' => 99]]]]]);
    }

    private function teamMessage(Target $target, array $row = []): TeamMessage
    {
        $teamMessage = $this->createStub(TeamMessage::class);
        $teamMessage->method('deliver')->willReturnCallback(function ($type, $reference, \Closure $compose) use (
            $target,
            $row
        ) {
            $this->composed = $compose($target, $row + ['sent_at' => null]);
        });
        return $teamMessage;
    }

    private function messages(): Messages
    {
        return new Messages($this->pool(['cron' => 'Cron', 'caches' => 'Caches'],
            ['email_disabled' => 'Email sending']));
    }

    private function alert(int $scope): void
    {
        (new Alert($this->teamMessage(new Target($scope, 1, 'slack', 'C' . $scope, 'Shop', 'UTC')), $this->store,
            $this->messages(), $this->config(), new Clock(self::NOW)))->process((string)$scope);
    }

    public function testTheShopWideAlertCarriesTheShopWideChecksAndRecordsWhatItTold(): void
    {
        $this->alert(0);
        $this->assertSame("App health - Shop (all websites)\n"
            . "FAIL Cron: no job has run for 12 min (since 07:48)\nOK Caches", $this->composed[0]);
        ($this->composed[1])();
        $this->assertSame(['cron' => ['status' => 'fail', 'at' => self::NOW]], $this->store->row('alerted:0'));
        $this->composed = null;
        $this->alert(0);
        $this->assertNull($this->composed, 'told already: nothing to send');
    }

    public function testAWebsitesAlertCarriesOnlyItsOwnChecks(): void
    {
        $this->alert(2);
        $this->assertSame("App health - Shop\nFAIL Email sending: emails are disabled (since 08:00)",
            $this->composed[0]);
        $this->composed = null;
        $this->alert(1);
        $this->assertNull($this->composed, 'website 1 is fine');
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
        $this->assertSame("Daily summary - Shop - 20 Oct\nFAIL Cron: no job has run for 12 min (since 07:48)\n"
            . "FAIL Email sending: emails are disabled (since 08:00)\nCron: 10 runs\nOK all day: Caches",
            $this->composed[0], 'the tally of 25 hours ago is left out');
    }

    public function testTheSummaryOnlyWhenDueAndOn(): void
    {
        $this->summary(['general/summary' => '1', 'general/summary_time' => '07,30,00'],
            ['sent_at' => gmdate('Y-m-d H:i:s', self::NOW - 600)]);
        $this->assertNull($this->composed, 'sent since 07:30');
        $this->summary(['general/summary' => '0', 'general/summary_time' => '07,30,00']);
        $this->assertNull($this->composed, 'off');
    }
}
