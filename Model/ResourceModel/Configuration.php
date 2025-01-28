<?php
namespace Payflex\Gateway\Model\ResourceModel;

use \Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Configuration extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('payflex_gateway_configuration', 'id');
    }
}
