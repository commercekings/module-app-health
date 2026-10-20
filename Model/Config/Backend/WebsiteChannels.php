<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Config\Backend;

use CommerceKings\Notification\Model\RecipientNormalizerPool;
use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;

/**
 * *Website channels*, checked on save: a website that exists, at most once; a channel code and a recipient on every
 * row, the recipient as the channel's normalizer gives it (Notification's) - one the channel can't take is refused.
 */
class WebsiteChannels extends ArraySerialized
{
    /** @param array<mixed> $data */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly StoreManagerInterface $storeManager,
        private readonly RecipientNormalizerPool $normalizers,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
        ?Json $serializer = null
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data,
            $serializer);
    }

    /**
     * @return $this
     * @throws LocalizedException for a row that is not valid
     */
    public function beforeSave()
    {
        $rows = $this->getData('value');
        $this->setData('value', $this->clean(is_array($rows) ? $rows : []));
        return parent::beforeSave();
    }

    /**
     * The rows as saved: valid, the recipients normalized; the dynamic rows' empty template left out.
     *
     * @param array<mixed> $rows
     * @return array<string, array{website_id: string, channel: string, recipient: string}>
     * @throws LocalizedException for a row that is not valid
     */
    public function clean(array $rows): array
    {
        $websites = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websites[(int)$website->getId()] = (string)$website->getName();
        }
        $seen = [];
        $clean = [];
        foreach ($rows as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $websiteId = (int)($row['website_id'] ?? 0);
            $channel = trim((string)($row['channel'] ?? ''));
            $recipient = trim((string)($row['recipient'] ?? ''));
            if (!isset($websites[$websiteId])) {
                throw new LocalizedException(__('Website channels: choose a website on every row.'));
            }
            if (isset($seen[$websiteId])) {
                throw new LocalizedException(__('Website channels: "%1" is there twice.', $websites[$websiteId]));
            }
            if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $channel) || $recipient === '') {
                throw new LocalizedException(__('Website channels: "%1" needs a channel code (e.g. slack) and a'
                    . ' recipient.', $websites[$websiteId]));
            }
            $normalized = $this->normalizers->normalize($channel, $recipient);
            if ($normalized === '') {
                throw new LocalizedException(__('Website channels: "%1" is not a recipient the "%2" channel takes.',
                    $recipient, $channel));
            }
            $seen[$websiteId] = true;
            $clean[(string)$id] = ['website_id' => (string)$websiteId, 'channel' => $channel,
                'recipient' => $normalized];
        }
        return $clean;
    }
}
