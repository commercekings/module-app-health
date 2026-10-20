<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Block\Adminhtml\System\Config;

use CommerceKings\AppHealth\Model\SiteDomain;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * *Shop domain*: the domain App health was set up on and the shop's now; when they differ (a copy, or a move) App
 * health is silent and a "Use <domain>" button makes this shop the one it reports on.
 */
class Domain extends Field
{
    /** @var string */
    protected $_template = 'CommerceKings_AppHealth::system/config/domain.phtml';

    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly SiteDomain $siteDomain,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getStored(): string
    {
        return $this->siteDomain->stored();
    }

    public function getCurrent(): string
    {
        return $this->siteDomain->current();
    }

    public function isCopy(): bool
    {
        return !$this->siteDomain->matches();
    }

    public function getAcceptUrl(): string
    {
        return $this->getUrl('ckapphealth/domain/accept');
    }
}
