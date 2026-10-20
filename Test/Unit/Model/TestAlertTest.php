<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Model\Targets;
use CommerceKings\AppHealth\Model\TestAlert;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Pool;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\SenderInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class TestAlertTest extends TestCase
{
    private const MESSAGES_NOW = 1792483200;

    use Pool;

    /** @var Delivery[] */
    private array $sent = [];

    private function testAlert(?Target $target, \Throwable|string $answer = SenderInterface::SENT,
        ?\CommerceKings\AppHealth\Model\SiteDomain $domain = null): TestAlert
    {
        $targets = $this->createStub(Targets::class);
        $targets->method('global')->willReturn($target);
        $sender = $this->createStub(SenderInterface::class);
        $sender->method('send')->willReturnCallback(function (Delivery $delivery) use ($answer) {
            $this->sent[] = $delivery;
            if ($answer instanceof \Throwable) {
                throw $answer;
            }
            return $answer;
        });
        return new TestAlert($targets, new Messages($this->pool([]), new Clock(self::MESSAGES_NOW)), $sender, $domain);
    }

    public function testACopyOfTheShopSendsNoTestAlert(): void
    {
        $copy = $this->createStub(\CommerceKings\AppHealth\Model\SiteDomain::class);
        $copy->method('matches')->willReturn(false);
        $copy->method('stored')->willReturn('shop.example.com');
        $copy->method('current')->willReturn('staging.example.com');
        try {
            $this->testAlert(new Target(0, 1, 'slack', 'C0123', 'Shop', 'UTC'), SenderInterface::SENT, $copy)->send();
            $this->fail('sent from a copy');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->assertStringContainsString('a copy of shop.example.com (now staging.example.com)', $e->getMessage());
        }
    }

    public function testSentAtOnceAsAServiceMessage(): void
    {
        $this->assertSame('C0123', $this->testAlert(new Target(0, 1, 'slack', 'C0123', 'Shop', 'UTC'))->send());
        $this->assertSame([false, false, 'app_health_test'], [$this->sent[0]->useCooldown, $this->sent[0]->stoppable,
            $this->sent[0]->type]);
        $this->assertStringStartsWith("*Test alert · Shop*\n\nThis channel gets",
            ($this->sent[0]->content)()->text);
    }

    public function testTheAdminSeesWhyNot(): void
    {
        $target = new Target(1, 1, 'slack', 'not-a-channel', 'Shop', 'UTC');
        $cases = [
            [null, SenderInterface::SENT, 'Set a channel and a recipient'],
            [$target, new \InvalidArgumentException('x'),
                '"not-a-channel" is not a recipient the "slack" channel takes.'],
            [$target, new \RuntimeException('channel_not_found'), 'Not sent (RuntimeException): channel_not_found'],
            [$target, SenderInterface::COOLDOWN, 'Not sent: recipient cooldown.'],
        ];
        foreach ($cases as [$caseTarget, $answer, $message]) {
            try {
                $this->testAlert($caseTarget, $answer)->send();
                $this->fail('sent');
            } catch (LocalizedException $e) {
                $this->assertStringStartsWith($message, $e->getMessage());
            }
        }
    }
}
