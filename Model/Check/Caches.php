<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Cache types disabled (fail) or invalidated for over "minutes" (warn). Magento keeps no invalidation time, so the
 * check remembers when it first saw each one. The type states come from env.php, the invalidated list is one cache
 * read.
 */
class Caches implements CheckInterface
{
    public const CODE = 'caches';

    public function __construct(
        private readonly TypeListInterface $typeList,
        private readonly Config $config,
        private readonly RunState $state,
        private readonly DateTime $dateTime
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getHint(): string
    {
        return (string)__('System > Cache Management: enable the cache types, or refresh the invalidated ones.');
    }

    public function getLabel(): string
    {
        return 'Caches';
    }

    public function run(): CheckResult
    {
        $now = $this->dateTime->gmtTimestamp();
        $disabled = [];
        foreach ($this->typeList->getTypes() as $id => $type) {
            if (!(int)$type->getData('status')) {
                $disabled[] = (string)$id;
            }
        }
        $seen = (array)($this->state->get('cursor:' . self::CODE)['since'] ?? []);
        $since = [];
        foreach (array_keys($this->typeList->getInvalidated()) as $id) {
            $since[(string)$id] = (int)($seen[(string)$id] ?? $now);
        }
        $this->state->set('cursor:' . self::CODE, ['since' => $since]);
        $limit = $now - $this->config->getInt(self::CODE, 'minutes', 60) * 60;
        $old = array_filter($since, fn (int $first) => $first <= $limit);
        sort($disabled);
        ksort($old);
        $parts = [];
        if ($disabled !== []) {
            $parts[] = Format::list($disabled) . ' disabled';
        }
        if ($old !== []) {
            $parts[] = Format::list(array_keys($old)) . ' invalidated for ' . Format::duration($now - min($old));
        }
        return match (true) {
            $disabled !== [] => CheckResult::fail(implode('; ', $parts), [], 'Cache disabled'),
            $old !== [] => CheckResult::warn(implode('; ', $parts), [], 'Cache invalidated'),
            default => CheckResult::ok(),
        };
    }
}
