<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Config\Backend;

use CommerceKings\AppHealth\Model\Sensitive\RegexGuard;
use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The sensitive-data "Rule settings" and "Custom rules" rows, checked on save: codes [a-z0-9_] ("set" or "set/rule"
 * for settings), on 0 or 1, severity critical or warning, and each custom pattern safe to run (RegexGuard: compiles,
 * length, no nested quantifier, finishes long lines).
 */
class SensitiveRules extends ArraySerialized
{
    /** @param array<mixed> $data */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly RegexGuard $guard,
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
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row['code'] ?? ''));
            $codes = isset($row['pattern']) ? '/^[a-z0-9_]{1,40}$/D' : '/^[a-z0-9_]{1,40}(\/[a-z0-9_]{1,40})?$/D';
            if (!preg_match($codes, $code) || (isset($row['category'])
                && !preg_match('/^[a-z0-9_]{1,40}$/D', trim((string)$row['category'])))) {
                throw new LocalizedException(__('"%1" is not a rule code: lowercase letters, digits and _ (a setting'
                    . ' may name a rule as set/rule).', $code));
            }
            $severity = trim((string)($row['severity'] ?? ''));
            if ($severity !== '' && !in_array($severity, ['critical', 'warning'], true)) {
                throw new LocalizedException(__('Severity is critical or warning, not "%1".', $severity));
            }
            $enabled = trim((string)($row['enabled'] ?? ''));
            if ($enabled !== '' && !in_array($enabled, ['0', '1'], true)) {
                throw new LocalizedException(__('On is 1 and off is 0, not "%1".', $enabled));
            }
            if (isset($row['pattern'])) {
                $why = $this->guard->check(trim((string)$row['pattern']));
                if ($why !== '') {
                    throw new LocalizedException(__('The pattern of rule "%1" is refused: %2.', $code, $why));
                }
            }
        }
        return parent::beforeSave();
    }
}
