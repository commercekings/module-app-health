<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Config;

use CommerceKings\AppHealth\Model\Config\Backend\HourlyMinutes;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

/** *Hourly status every*: whole minutes from 15 to 180, as the admin says - also through bin/magento config:set. */
class HourlyMinutesTest extends TestCase
{
    private function save(string $value): string
    {
        $context = $this->createConfiguredStub(Context::class, [
            'getEventDispatcher' => $this->createStub(\Magento\Framework\Event\ManagerInterface::class),
        ]);
        $field = new HourlyMinutes($context, $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class), $this->createStub(TypeListInterface::class));
        $field->setValue($value);
        $field->beforeSave();
        return (string)$field->getValue();
    }

    public function testFifteenToOneHundredEightyMinutes(): void
    {
        $this->assertSame(['15', '60', '180', '45'], [$this->save('15'), $this->save('60'), $this->save('180'),
            $this->save(' 45 ')]);
        foreach (['14', '181', '0', '', '30.5', '1e2', 'sixty'] as $bad) {
            try {
                $this->save($bad);
                $this->fail('saved ' . $bad);
            } catch (LocalizedException $e) {
                $this->assertSame('Hourly status every: whole minutes from 15 to 180.', $e->getMessage(), $bad);
            }
        }
    }
}
