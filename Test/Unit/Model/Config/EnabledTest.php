<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Config;

use CommerceKings\AppHealth\Model\Config\Backend\Enabled;
use CommerceKings\AppHealth\Model\SiteDomain;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Saving *Enabled* on: a channel and recipient are needed, a missing heartbeat is warned about, and the shop's domain is
 * stored the first time only - never over one stored before.
 */
class EnabledTest extends TestCase
{
    private int $accepted = 0;
    /** @var string[] */
    private array $warnings = [];
    /** @var array<string, string> saved settings (path after "commercekings_app_health/general/") */
    private array $saved = [];

    /** @param array<string, string>|null $posted the Alerts group's fields as submitted, null: not from the form */
    private function enabled(string $value, string $stored = '', ?array $posted = ['channel' => 'slack',
        'recipient' => 'C0123', 'heartbeat_url' => 'https://hc.example/ping/1']): Enabled
    {
        $domain = $this->createStub(SiteDomain::class);
        $domain->method('stored')->willReturn($stored);
        $domain->method('accept')->willReturnCallback(function () {
            $this->accepted++;
        });
        $context = $this->createConfiguredStub(Context::class, [
            'getEventDispatcher' => $this->createStub(\Magento\Framework\Event\ManagerInterface::class),
            'getCacheManager' => $this->createStub(\Magento\Framework\App\CacheInterface::class),
        ]);
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn ($path) => $this->saved[substr((string)$path,
            strlen('commercekings_app_health/general/'))] ?? null);
        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addWarningMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->warnings[] = (string)$message;
            return $messages;
        });
        $enabled = new Enabled($context, $this->createStub(Registry::class), $scopeConfig,
            $this->createStub(TypeListInterface::class), $domain, $messages);
        $enabled->setValue($value);
        if ($posted !== null) {
            $enabled->setData('fieldset_data', $posted);
        }
        return $enabled;
    }

    public function testTheFirstSaveOnStoresTheDomain(): void
    {
        $this->enabled('1')->afterSave();
        $this->assertSame(1, $this->accepted);
    }

    public function testNeverOverAStoredDomainNorWhileOff(): void
    {
        $this->enabled('1', 'shop.example.com')->afterSave();
        $this->enabled('0')->afterSave();
        $this->assertSame(0, $this->accepted, 'a copy saved on stays a copy; off stores nothing');
    }

    public function testOnNeedsAChannelAndARecipient(): void
    {
        foreach ([['channel' => 'slack', 'recipient' => ' '], ['channel' => '', 'recipient' => 'C0123']] as $posted) {
            try {
                $this->enabled('1', '', $posted)->beforeSave();
                $this->fail('saved on without ' . json_encode($posted));
            } catch (LocalizedException $e) {
                $this->assertSame('App health needs a Channel and a Recipient to switch on: set both under Alerts.',
                    $e->getMessage());
            }
        }
        $this->enabled('0', '', ['channel' => '', 'recipient' => ''])->beforeSave();
        $this->saved = ['channel' => 'slack', 'recipient' => 'C0123'];
        $this->enabled('1', '', null)->beforeSave();
        $this->addToAssertionCount(1); // off without them, or on from the CLI with them saved: fine
    }

    public function testOnWithoutAHeartbeatIsWarned(): void
    {
        $this->enabled('1', 'shop.example.com', ['channel' => 'slack', 'recipient' => 'C0123', 'heartbeat_url' => ''])
            ->afterSave();
        $this->assertSame(['App health is on without a Heartbeat URL: when the server, cron or the queue is down, nothing'
            . ' can tell you. Add one under Alerts (see the README, Before going live).'], $this->warnings);
        $this->warnings = [];
        $this->enabled('1', 'shop.example.com')->afterSave();
        $this->enabled('0', 'shop.example.com', ['heartbeat_url' => ''])->afterSave();
        $this->assertSame([], $this->warnings);
    }
}
