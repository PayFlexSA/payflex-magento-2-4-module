<?php
namespace Payflex\Gateway\Model\ResourceModel\RequestToken;

use \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init('Payflex\Gateway\Model\RequestToken', 'Payflex\Gateway\Model\ResourceModel\RequestToken');
    }
}
