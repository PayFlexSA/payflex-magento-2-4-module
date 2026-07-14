<?php

namespace Payflex\Gateway\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Per-store merchant configuration (spend limits, refund eligibility) mirrored
 * from the Payflex API.
 */
class Configuration extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Payflex\Gateway\Model\ResourceModel\Configuration::class);
    }
}
