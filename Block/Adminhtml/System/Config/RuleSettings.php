<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/** Rows of a rule set or a rule ("pci" or "pci/card_number") turned on or off, or given another severity. */
class RuleSettings extends AbstractFieldArray
{
    protected function _prepareToRender()
    {
        $this->addColumn('code', ['label' => __('Rule set or rule'), 'class' => 'required-entry']);
        $this->addColumn('enabled', ['label' => __('On (1) / off (0)')]);
        $this->addColumn('severity', ['label' => __('Severity (critical / warning)')]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add a setting');
    }
}
