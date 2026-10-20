<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Config\Backend;

use CommerceKings\AppHealth\Model\SiteDomain;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * *Enabled*: on needs a Channel and a Recipient (else nothing could be sent, and the heartbeat would ping for a shop
 * that tells no one), and without a Heartbeat URL the admin is warned. Saved on, it stores the shop's domain the first
 * time (SiteDomain) - so a copy of this database somewhere else stays silent. Only the first time: a later change of
 * domain is accepted by an admin on purpose ("Use <domain>"), never by saving the settings on a copy.
 *
 * The fields come as submitted with it (the Alerts group), else as saved (bin/magento config:set).
 */
class Enabled extends Value
{
    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly SiteDomain $siteDomain,
        private readonly ManagerInterface $messageManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /** @throws LocalizedException switched on without a channel or a recipient */
    public function beforeSave()
    {
        if ($this->isOn() && ($this->field('channel') === '' || $this->field('recipient') === '')) {
            throw new LocalizedException(__('App health needs a Channel and a Recipient to switch on: set both under'
                . ' Alerts.'));
        }
        return parent::beforeSave();
    }

    public function afterSave()
    {
        if ($this->isOn()) {
            if ($this->siteDomain->stored() === '') {
                $this->siteDomain->accept();
            }
            if ($this->field('heartbeat_url') === '') {
                $this->messageManager->addWarningMessage(__('App health is on without a Heartbeat URL: when the'
                    . ' server, cron or the queue is down, nothing can tell you. Add one under Alerts (see the README,'
                    . ' Before going live).'));
            }
        }
        return parent::afterSave();
    }

    private function isOn(): bool
    {
        return (bool)(int)$this->getValue();
    }

    /** A field of the Alerts group as submitted with this one, else as saved. */
    private function field(string $id): string
    {
        $posted = $this->getData('fieldset_data');
        $value = is_array($posted) && array_key_exists($id, $posted) ? $posted[$id]
            : $this->_config->getValue('commercekings_app_health/general/' . $id);
        return trim((string)$value);
    }
}
