<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Controller;

use CommerceKings\AppHealth\Controller\Adminhtml\Domain\Accept;
use CommerceKings\AppHealth\Model\SiteDomain;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;

/** "Use <domain>": stores the current domain - and says so only when it took (not pinned in config.php or env.php). */
class DomainAcceptTest extends TestCase
{
    /** @return array<string, mixed> the JSON answer */
    private function accept(string $current, string $storedAfter): array
    {
        $domain = $this->createMock(SiteDomain::class);
        $domain->method('current')->willReturn($current);
        $domain->method('stored')->willReturn($storedAfter);
        $domain->expects($current === '' ? $this->never() : $this->once())->method('accept');
        $answer = [];
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function (array $data) use (&$answer, $json) {
            $answer = $data;
            return $json;
        });
        $factory = $this->createConfiguredStub(JsonFactory::class, ['create' => $json]);
        (new Accept($this->createStub(Context::class), $factory, $domain))->execute();
        return $answer;
    }

    public function testTheNewDomainIsStored(): void
    {
        $this->assertSame(['success' => true, 'message' => 'App health now reports on shop.example.com. Reload the'
            . ' page.'], $this->accept('shop.example.com', 'shop.example.com'));
    }

    public function testADomainPinnedInConfigPhpIsNotReportedAsChanged(): void
    {
        $this->assertSame(['success' => false, 'message' => 'App health\'s domain is pinned to old.example.com in'
            . ' app/etc/config.php or env.php (commercekings_app_health/general/domain): change it there.'],
            $this->accept('shop.example.com', 'old.example.com'));
    }

    public function testWithoutADomainNothingIsStored(): void
    {
        $this->assertFalse($this->accept('', '')['success']);
    }
}
