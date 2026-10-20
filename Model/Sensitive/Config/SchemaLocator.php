<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Sensitive\Config;

use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader;

/** etc/app_health_rules.xsd, for each file and for the merged result. */
class SchemaLocator implements SchemaLocatorInterface
{
    private readonly string $schema;

    public function __construct(Reader $moduleReader)
    {
        $this->schema = $moduleReader->getModuleDir(Dir::MODULE_ETC_DIR, 'CommerceKings_AppHealth')
            . '/app_health_rules.xsd';
    }

    public function getSchema()
    {
        return $this->schema;
    }

    public function getPerFileSchema()
    {
        return $this->schema;
    }
}
