<?php

namespace Payflex\Gateway\Model;

use Magento\Framework\Model\AbstractModel;

class CheckOrderStatus extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Payflex\Gateway\Model\ResourceModel\CheckOrderStatus::class);
    }
}
