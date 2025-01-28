<?php
namespace Payflex\Gateway\Model\ResourceModel\RequestAccessToken;

use \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init('Payflex\Gateway\Model\RequestAccessToken', 'Payflex\Gateway\Model\ResourceModel\RequestAccessToken');
    }
}
