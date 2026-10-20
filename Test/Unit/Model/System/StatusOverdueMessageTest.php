<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\System;

use CommerceKings\AppHealth\Model\SiteDomain;
use CommerceKings\AppHealth\Model\System\StatusOverdueMessage;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use Magento\Framework\Notification\MessageInterface;
use PHPUnit\Framework\TestCase;

/** The admin is told when the hourly status - the proof that alerts go out - has been due too long. */
class StatusOverdueMessageTest extends TestCase
{
    use Settings;

    private MemoryStore $store;
    private Clock $clock;
    private bool $copy = false;
    /** @var array<string, string> */
    private array $settings = ['general/enabled' => '1', 'general/hourly_status' => '1'];

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->clock = new Clock();
    }

    private function message(): StatusOverdueMessage
    {
        $domain = $this->createStub(SiteDomain::class);
        $domain->method('matches')->willReturnCallback(fn () => !$this->copy);
        return new StatusOverdueMessage($this->config($this->settings), $this->store, $domain, $this->clock);
    }

    private function dueFor(int $minutes): void
    {
        $this->store->rows = [];
        $this->store->seed(['status' => ['due_since' => $this->clock->now - $minutes * 60]]);
    }

    public function testShownOnceTheStatusIsLateByMoreThanTheGrace(): void
    {
        $this->assertFalse($this->message()->isDisplayed(), 'never due');
        $this->dueFor(14);
        $this->assertFalse($this->message()->isDisplayed(), 'a run may still deliver it');
        $this->dueFor(47);
        $message = $this->message();
        $this->assertTrue($message->isDisplayed());
        $this->assertSame('App health\'s hourly status is 47 min overdue, so alerts may not be reaching you. Is the'
            . ' queue consumer commerceKingsNotificationSend running? (Magento\'s cron starts it with'
            . ' cron_consumers_runner in app/etc/env.php, else bin/magento queue:consumers:start'
            . ' commerceKingsNotificationSend.)', $message->getText());
        $this->assertSame(MessageInterface::SEVERITY_MAJOR, $message->getSeverity());
        $this->assertSame(1, $this->store->statements - 2, 'one read for the message');
    }

    public function testNotWhenItCannotBeDue(): void
    {
        $this->dueFor(47);
        foreach ([['general/enabled' => '0'], ['general/hourly_status' => '0']] as $off) {
            $this->settings = $off + ['general/enabled' => '1', 'general/hourly_status' => '1'];
            $this->assertFalse($this->message()->isDisplayed(), json_encode($off));
        }
        $this->settings = ['general/enabled' => '1', 'general/hourly_status' => '1'];
        $this->copy = true;
        $this->assertFalse($this->message()->isDisplayed(), 'a copy is silent on purpose');
    }
}
