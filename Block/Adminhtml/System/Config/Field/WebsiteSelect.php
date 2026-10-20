<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Block\Adminhtml\System\Config\Field;

use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;
use Magento\Store\Model\StoreManagerInterface;

/** The websites, for a *Website channels* row. */
class WebsiteSelect extends Select
{
    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @param string $value the row's input name */
    public function setInputName($value): self
    {
        return $this->setName($value);
    }

    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            foreach ($this->storeManager->getWebsites() as $website) {
                $this->addOption((string)$website->getId(), (string)$website->getName());
            }
        }
        return parent::_toHtml();
    }
}
