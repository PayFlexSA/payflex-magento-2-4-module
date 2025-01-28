<?php

namespace Payflex\Gateway\Model\Config\Backend;

class PayflexSupportLink extends \Magento\Config\Block\System\Config\Form\Field
{

    // We need to output the link to the Payflex Support page
    // This is done by overriding the _getHtml function

    protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        return '<a href="' . $this->_urlBuilder->getUrl('payflex/index/info') . '" target="_blank">Payflex Support</a>';
    }

}
