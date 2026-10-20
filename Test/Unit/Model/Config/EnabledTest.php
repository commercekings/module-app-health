<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Config;

use CommerceKings\AppHealth\Model\Config\Backend\Enabled;
use CommerceKings\AppHealth\Model\SiteDomain;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

/** Saving *Enabled* on stores the shop's domain the first time only - never over one stored before. */
class EnabledTest extends TestCase
{
    private function save(string $value, string $stored): int
    {
        $domain = $this->createStub(SiteDomain::class);
        $domain->method('stored')->willReturn($stored);
        $accepted = 0;
        $domain->method('accept')->willReturnCallback(function () use (&$accepted) {
            $accepted++;
        });
        $context = $this->createConfiguredStub(Context::class, [
            'getEventDispatcher' => $this->createStub(\Magento\Framework\Event\ManagerInterface::class),
            'getCacheManager' => $this->createStub(\Magento\Framework\App\CacheInterface::class),
        ]);
        $enabled = new Enabled($context, $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class), $this->createStub(TypeListInterface::class), $domain);
        $enabled->setValue($value);
        $enabled->afterSave();
        return $accepted;
    }

    public function testTheFirstSaveOnStoresTheDomain(): void
    {
        $this->assertSame(1, $this->save('1', ''));
    }

    public function testNeverOverAStoredDomainNorWhileOff(): void
    {
        $this->assertSame(0, $this->save('1', 'shop.example.com'), 'a copy saved on stays a copy');
        $this->assertSame(0, $this->save('0', ''), 'off');
    }
}
