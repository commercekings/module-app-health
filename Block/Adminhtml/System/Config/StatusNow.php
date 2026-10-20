<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Block\Adminhtml\System\Config;

use CommerceKings\AppHealth\Model\Targets;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * The "Send status now" button: posts to ckapphealth/status/send and shows the answer; disabled, with what to set,
 * while app health is off or has no global channel and recipient. Shown only to admins allowed to send it.
 */
class StatusNow extends Field
{
    /** @var string */
    protected $_template = 'CommerceKings_AppHealth::system/config/status-now.phtml';

    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly Targets $targets,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        if (!$this->_authorization->isAllowed('CommerceKings_AppHealth::send_status')) {
            return '';
        }
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getSendUrl(): string
    {
        return $this->getUrl('ckapphealth/status/send');
    }

    /** As StatusNow::isAvailable(), without building the checks on every configuration page. */
    public function isAvailable(): bool
    {
        return $this->targets->shopWide() !== null;
    }
}
