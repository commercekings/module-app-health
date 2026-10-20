<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\EmailFailing;
use CommerceKings\AppHealth\Model\ExceptionLog;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Db;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use PHPUnit\Framework\TestCase;

class EmailFailingTest extends TestCase
{
    use Db;
    use Settings;

    private function check(int $mail, array $failing): CheckResult
    {
        $log = $this->createStub(ExceptionLog::class);
        $log->method('newEntries')
            ->willReturn(['entries' => $mail, 'classes' => [], 'mail' => $mail, 'partial' => false]);
        $state = $this->createStub(DeliveryStateInterface::class);
        $state->method('getTableName')->willReturn('ck_notification_delivery_state');
        return (new EmailFailing($this->resource($this->db(['fetchPairs' => [$failing]])), $state, $log,
            $this->config(['email_failing/failed_reminders' => '3']), new Clock()))->run();
    }

    public function testMailTransportErrorsAndFailingNotifications(): void
    {
        $this->assertSame(CheckResult::OK, $this->check(0, ['cart_reminder' => 2])->status);
        $this->assertSame(['mail errors' => 0], $this->check(0, [])->counts);
        $mail = $this->check(2, []);
        $this->assertSame(CheckResult::FAIL, $mail->status);
        $this->assertSame('2 new mail transport errors', $mail->detail);
        $this->assertSame(['mail errors' => 2], $mail->counts);
        $this->assertSame('3 notifications failing (cart_reminder_sms x2, cart_reminder x1)',
            $this->check(0, ['cart_reminder' => 1, 'cart_reminder_sms' => 2])->detail);
    }

    public function testOnlyRecentFailuresOfOtherModulesOnTheUpdatedAtIndex(): void
    {
        $this->check(0, []);
        $wheres = $this->wheres();
        $this->assertContains('updated_at >= ?', $wheres);
        $this->assertContains('type NOT LIKE ?', $wheres, 'this module\'s own messages are not counted');
        $this->assertContains(['limit', 100], array_map(fn ($call) => array_slice($call, 0, 2), $this->calls));
    }
}
