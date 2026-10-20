<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Controller;

use CommerceKings\AppHealth\Controller\Adminhtml\Test\Send;
use CommerceKings\AppHealth\Model\TestAlert;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/** "Send test alert": the answer the admin sees - including that App health is off, when the test still went out. */
class TestSendTest extends TestCase
{
    use Settings;

    /** @return array<string, mixed> the JSON */
    private function send(bool $enabled, \Throwable|string $answer = 'C0123'): array
    {
        $alert = $this->createStub(TestAlert::class);
        $alert->method('send')->willReturnCallback(fn () => $answer instanceof \Throwable ? throw $answer : $answer);
        $data = [];
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($set) use (&$data, $json) {
            $data = $set;
            return $json;
        });
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($json);
        (new Send($this->createStub(Context::class), $factory, $alert,
            $this->config(['general/enabled' => $enabled ? '1' : '0'])))->execute();
        return $data;
    }

    public function testSentWhileOnSaysWhere(): void
    {
        $this->assertSame(['success' => true, 'message' => 'Test alert sent to C0123.'], $this->send(true));
    }

    public function testSentWhileOffSaysThatNothingElseWillCome(): void
    {
        $this->assertSame(['success' => true, 'message' => 'Test alert sent to C0123. App health is off: no check'
            . ' runs and no alert is sent until you set Enabled to Yes and save.'], $this->send(false));
    }

    public function testNotSentSaysWhy(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Not sent: channel_not_found'],
            $this->send(true, new LocalizedException(__('Not sent: channel_not_found'))));
    }
}
