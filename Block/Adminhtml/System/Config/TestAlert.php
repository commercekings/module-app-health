<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** The "Send test alert" button: posts to ckapphealth/test/send and shows the answer. */
class TestAlert extends Field
{
    /** @var string */
    protected $_template = 'CommerceKings_AppHealth::system/config/test-alert.phtml';

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getSendUrl(): string
    {
        return $this->getUrl('ckapphealth/test/send');
    }

}
