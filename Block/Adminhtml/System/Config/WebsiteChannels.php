<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Block\Adminhtml\System\Config;

use CommerceKings\AppHealth\Block\Adminhtml\System\Config\Field\WebsiteSelect;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;

/** *Website channels*: a row per website - the website, its channel and its recipient. */
class WebsiteChannels extends AbstractFieldArray
{
    private ?WebsiteSelect $websites = null;

    protected function _prepareToRender()
    {
        $this->addColumn('website_id', ['label' => __('Website'), 'renderer' => $this->websites()]);
        $this->addColumn('channel', ['label' => __('Channel (e.g. slack)'), 'class' => 'required-entry']);
        $this->addColumn('recipient', ['label' => __('Recipient'), 'class' => 'required-entry']);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add a website channel');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $row->setData('option_extra_attrs', ['option_' . $this->websites()->calcOptionHash(
            (string)$row->getData('website_id')) => 'selected="selected"']);
    }

    private function websites(): WebsiteSelect
    {
        if ($this->websites === null) {
            /** @var WebsiteSelect $select */
            $select = $this->getLayout()->createBlock(WebsiteSelect::class, '', ['data' => ['is_render_to_js_template'
                => true]]);
            $this->websites = $select;
        }
        return $this->websites;
    }
}
