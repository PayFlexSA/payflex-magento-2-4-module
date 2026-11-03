<?php
namespace Payflex\Gateway\Model\ResourceModel;

use \Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class RequestToken extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('payflex_gateway', 'id');
    }

    /**
     * The Payflex order ids created for a Magento order increment id, oldest first.
     */
    public function getPayflexIds($orderIncrementId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), 'payflex_id')
            ->where('order_id = ?', (string) $orderIncrementId)
            ->order('id ASC');

        return $connection->fetchCol($select);
    }
}
