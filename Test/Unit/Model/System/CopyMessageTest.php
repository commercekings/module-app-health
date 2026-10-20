<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\System;

use CommerceKings\AppHealth\Model\SiteDomain;
use CommerceKings\AppHealth\Model\System\CopyMessage;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use Magento\Framework\Notification\MessageInterface;
use PHPUnit\Framework\TestCase;

/** The admin is told when App health is on but silent on a copy, with both domains and where to accept. */
class CopyMessageTest extends TestCase
{
    use Settings;

    private function message(bool $enabled, string $stored, string $current): CopyMessage
    {
        $domain = $this->createStub(SiteDomain::class);
        $domain->method('stored')->willReturn($stored);
        $domain->method('current')->willReturn($current);
        $domain->method('matches')->willReturn($stored === '' || $stored === $current);
        return new CopyMessage($this->config(['general/enabled' => $enabled ? '1' : '0']), $domain);
    }

    public function testShownOnlyWhileOnAndACopy(): void
    {
        $this->assertTrue($this->message(true, 'shop.example.com', 'staging.example.com')->isDisplayed());
        $this->assertFalse($this->message(true, 'shop.example.com', 'shop.example.com')->isDisplayed());
        $this->assertFalse($this->message(true, '', 'staging.example.com')->isDisplayed(), 'nothing stored yet');
        $this->assertFalse($this->message(false, 'shop.example.com', 'staging.example.com')->isDisplayed(), 'off');
    }

    public function testItNamesBothDomainsAndWhereToAccept(): void
    {
        $message = $this->message(true, 'shop.example.com', 'staging.example.com');
        $this->assertSame('App health is silent: this looks like a copy of shop.example.com (this shop is'
            . ' staging.example.com), so it sends no alerts and pings no heartbeat. If the shop has moved, use the new'
            . ' domain in Stores > Configuration > CommerceKings > App health.', $message->getText());
        $this->assertSame(MessageInterface::SEVERITY_MAJOR, $message->getSeverity());
        $this->assertNotSame($message->getIdentity(), $this->message(true, 'shop.example.com', 'other.example.com')
            ->getIdentity(), 'another copy: a message of its own');
    }
}
