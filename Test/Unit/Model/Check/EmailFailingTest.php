<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\EmailFailing;
use CommerceKings\AppHealth\Model\ExceptionLog;
use CommerceKings\AppHealth\Model\Log\LogReader;
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

    private function check(array $exceptions, array $system, array $failing): CheckResult
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
        return (new EmailFailing($this->resource($this->db(['fetchPairs' => [$failing]])), $state, $log, $reader,
            $this->config(['email_failing/failed_reminders' => '3']), new Clock()))->run();
    }

    public function testMailTransportErrorsFromBothLogsWithTheLatestReasonMasked(): void
    {
        $this->assertSame(CheckResult::OK, $this->check([], [self::entry(false, 'other', 1)],
            ['cart_reminder' => 2])->status);
        $result = $this->check([self::entry(true, 'Unable to send mail. Please try again later.', 100)],
            [self::entry(true, 'Transport error while sending email: Connection to *** refused', 200,
                'vendor/magento/module-email/Model/Transport.php:240')], []);
        $this->assertSame([CheckResult::FAIL, 'Mail sending failing'], [$result->status, $result->title]);
        $this->assertSame('2 new mail transport errors', $result->detail);
        $this->assertSame(['mail errors' => 2], $result->counts);
        $this->assertSame('mail_transport', $result->causes[0]->code);
        $this->assertSame('the mail server refused or could not be reached: Transport error while sending email:'
            . ' Connection to *** refused (vendor/magento/module-email/Model/Transport.php:240)',
            $result->causes[0]->detail, 'the latest one');
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
}
