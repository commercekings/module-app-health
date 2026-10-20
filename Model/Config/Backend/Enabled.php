<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Config\Backend;

use CommerceKings\AppHealth\Model\SiteDomain;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * *Enabled*: saved on, it stores the shop's domain the first time (SiteDomain) - so a copy of this database somewhere
 * else stays silent. Only the first time: a later change of domain is accepted by an admin on purpose ("Use
 * <domain>"), never by saving the settings on a copy.
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
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function afterSave()
    {
        if ((bool)(int)$this->getValue() && $this->siteDomain->stored() === '') {
            $this->siteDomain->accept();
        }
        return parent::afterSave();
    }
}
