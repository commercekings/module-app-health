<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\SiteDomain;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/** The domain App health was set up on against the shop's own now: a copy does not match. */
class SiteDomainTest extends TestCase
{
    private string $stored = '';
    private string $baseUrl = 'https://Shop.Example.com/';
    /** @var array<string, string> */
    private array $written = [];
    private int $reinits = 0;

    private function domain(): SiteDomain
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn ($path) => $path === SiteDomain::PATH
            ? $this->stored : null);
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturnCallback(fn () => $this->baseUrl);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getDefaultStoreView')->willReturn($store);
        $writer = $this->createStub(WriterInterface::class);
        $writer->method('save')->willReturnCallback(function ($path, $value) {
            $this->written[$path] = $value;
        });
        $reinit = $this->createStub(ReinitableConfigInterface::class);
        $reinit->method('reinit')->willReturnCallback(function () use ($reinit) {
            $this->reinits++;
            return $reinit;
        });
        return new SiteDomain($scopeConfig, $stores, $writer, $reinit);
    }

    public function testNothingStoredMatches(): void
    {
        $this->assertSame(['shop.example.com', '', true], [$this->domain()->current(), $this->domain()->stored(),
            $this->domain()->matches()], 'set up on no shop yet: not silent');
    }

    public function testACopyDoesNotMatchUntilItIsAccepted(): void
    {
        $this->stored = 'Shop.Example.com ';
        $this->assertTrue($this->domain()->matches(), 'the same shop, whatever the case');
        $this->baseUrl = 'https://staging.shop.example.com/';
        $this->assertFalse($this->domain()->matches(), 'a copy');
        $this->domain()->accept();
        $this->assertSame([SiteDomain::PATH => 'staging.shop.example.com'], $this->written);
        $this->assertSame(1, $this->reinits, 'read again at once');
    }

    public function testAnUnknownBaseUrlIsNeverStored(): void
    {
        $this->baseUrl = '';
        $this->domain()->accept();
        $this->assertSame([], $this->written);
        $this->assertSame('', $this->domain()->current());
    }
}
