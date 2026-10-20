<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\EmailFailing;
use CommerceKings\AppHealth\Model\ExceptionLog;
use CommerceKings\AppHealth\Model\Log\LogReader;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Db;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use PHPUnit\Framework\TestCase;

class EmailFailingTest extends TestCase
{
    use Db;
    use Settings;

    /** A safe entry, mail or not. */
    private static function entry(bool $mail, string $message, int $time, ?string $location = null): array
    {
        return ['rank' => 2, 'level' => 'ERROR', 'channel' => 'main', 'classes' => [], 'location' => $location,
            'fingerprint' => 'abcd1234', 'label' => 'label', 'message' => $message, 'time' => $time, 'mail' => $mail];
    }

    private ?RunState $state = null;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->clock = new Clock();
        $this->state = new RunState(new MemoryStore());
        $this->state->begin();
    }

    /** @param array<int, mixed> $sent what the "sent since" query answers, in order */
    private function check(array $exceptions, array $system, array $failing, array $sent = []): CheckResult
    {
        $log = $this->createStub(ExceptionLog::class);
        $log->method('newEntries')->willReturn(['entries' => count($exceptions), 'classes' => [], 'mail' => 0,
            'partial' => false, 'safe' => $exceptions]);
        $reader = $this->createStub(LogReader::class);
        $reader->method('entries')->willReturnCallback(function ($check) use ($system) {
            $this->assertSame('system_log', $check);
            return ['entries' => $system, 'partial' => false];
        });
        $state = $this->createStub(DeliveryStateInterface::class);
        $state->method('getTableName')->willReturn('ck_notification_delivery_state');
        $this->calls = [];
        $result = (new EmailFailing($this->resource($this->db(['fetchPairs' => [$failing], 'fetchOne' => $sent])),
            $state, $log, $reader, $this->config(['email_failing/failed_reminders' => '3',
            'email_failing/quiet_minutes' => '60']), $this->clock, $this->state))->run();
        $this->clock->now += 300;
        return $result;
    }

    public function testMailTransportErrorsFromBothLogsWithTheLatestReasonMasked(): void
    {
        $this->assertSame(CheckResult::OK, $this->check([], [self::entry(false, 'other', 1)],
            ['cart_reminder' => 2])->status);
        $result = $this->check([self::entry(true, 'Unable to send mail. Please try again later.', 100)],
            [self::entry(true, 'Transport error while sending email: Connection could not be established with host ***'
                . ' (Connection refused)', 200, 'vendor/magento/module-email/Model/Transport.php:240')
                + ['mail_reason' => ['code' => 'refused', 'reason' => 'the mail server refused the connection',
                    'port' => 465]]], []);
        $this->assertSame([CheckResult::FAIL, 'Mail sending failing'], [$result->status, $result->title]);
        $this->assertSame('2 new mail transport errors: the mail server refused the connection', $result->detail);
        $this->assertSame(['mail errors' => 2], $result->counts);
        $this->assertSame('mail_refused', $result->causes[0]->code);
        $this->assertSame('The mail server refused the connection (port 465): Transport error while sending email:'
            . ' Connection could not be established with host *** (Connection refused)'
            . ' (vendor/magento/module-email/Model/Transport.php:240)', $result->causes[0]->detail, 'the latest one');
        $this->assertStringContainsString('firewall', $result->causes[0]->hint);
    }

    public function testFailingNotifications(): void
    {
        $result = $this->check([], [], ['cart_reminder' => 1, 'cart_reminder_sms' => 2]);
        $this->assertSame('3 notifications failing (cart_reminder_sms x2, cart_reminder x1)', $result->detail);
        $this->assertSame('notifications_failing', $result->causes[0]->code);
    }

    public function testOnlyRecentFailuresOfOtherModulesOnTheUpdatedAtIndex(): void
    {
        $this->check([], [], []);
        $wheres = $this->wheres();
        $this->assertContains('updated_at >= ?', $wheres);
        $this->assertContains('type NOT LIKE ?', $wheres, 'this module\'s own messages are not counted');
        $this->assertContains(['limit', 100], array_map(fn ($call) => array_slice($call, 0, 2), $this->calls));
    }

    /** @return array<string, mixed> a mail error entry */
    private static function refused(): array
    {
        return self::entry(true, 'Transport error while sending email: Connection refused', 100) + ['mail_reason' => [
            'code' => 'refused', 'reason' => 'the mail server refused the connection', 'port' => 465]];
    }

    public function testAQuietRunIsNoRecoveryUntilAMailGoesOut(): void
    {
        $this->assertSame(CheckResult::FAIL, $this->check([], [self::refused()], [])->status);
        $quiet = $this->check([], [], [], [false]);
        $this->assertSame(CheckResult::FAIL, $quiet->status, 'nobody sent anything: still broken');
        $this->assertStringStartsWith('no successful send since the last mail transport error at {t:', $quiet->detail);
        $this->assertSame('mail_refused', $quiet->causes[0]->code, 'the cause is kept');
        $this->assertContains('email_sent = 1', $this->wheres(), 'a sales email marked sent');
        $this->assertContains('sent_at >= ?', $this->wheres(), 'or a CommerceKings notification sent');
        $this->assertSame(CheckResult::OK, $this->check([], [], [], [1])->status, 'a mail went out: recovered');
        $this->assertSame(CheckResult::OK, $this->check([], [], [], [])->status);
    }

    public function testQuietLongEnoughIsARecoveryToo(): void
    {
        $this->check([], [self::refused()], []);
        $this->clock->now += 55 * 60;
        $this->assertSame(CheckResult::OK, $this->check([], [], [], [false])->status, 'an hour without a new error');
    }
}
