<?php
namespace Payflex\Gateway\Model\ResourceModel\Configuration;

use \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init('Payflex\Gateway\Model\Configuration', 'Payflex\Gateway\Model\ResourceModel\Configuration');
    }
}
