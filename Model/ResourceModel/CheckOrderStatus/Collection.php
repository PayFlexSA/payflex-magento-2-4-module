<?php
namespace Payflex\Gateway\Model\ResourceModel\Configuration;

use \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class CheckOrderStatus extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init('Payflex\Gateway\Model\CheckOrderStatus', 'Payflex\Gateway\Model\ResourceModel\CheckOrderStatus');
    }
}
