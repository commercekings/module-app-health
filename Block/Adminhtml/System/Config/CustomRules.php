<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/** Rows of regex rules of the shop's own, in a shipped rule set or a new one. */
class CustomRules extends AbstractFieldArray
{
    protected function _prepareToRender()
    {
        $this->addColumn('code', ['label' => __('Code'), 'class' => 'required-entry']);
        $this->addColumn('category', ['label' => __('Rule set'), 'class' => 'required-entry']);
        $this->addColumn('label', ['label' => __('Label')]);
        $this->addColumn('pattern', ['label' => __('Pattern (e.g. /secret_[0-9]{8}/)'), 'class' => 'required-entry']);
        $this->addColumn('severity', ['label' => __('Severity')]);
        $this->addColumn('min', ['label' => __('At least (per entry)')]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add a rule');
    }
}
