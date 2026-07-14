<?php
namespace Payflex\Gateway\Model\ResourceModel\CheckOrderStatus;

use \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(
            \Payflex\Gateway\Model\CheckOrderStatus::class,
            \Payflex\Gateway\Model\ResourceModel\CheckOrderStatus::class
        );
    }
}
