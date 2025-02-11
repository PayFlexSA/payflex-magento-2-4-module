<?php

namespace Payflex\Gateway\Model;

use \Magento\Framework\Model\AbstractModel;

class CheckOrderStatus extends AbstractModel
{

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    )
    {
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
        $this->_logger = $this->_objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");

        $this->_logger->info(__METHOD__);
    }

    protected function _construct()
    {
        $this->_logger->info(__METHOD__);
        $this->_init('Payflex\Gateway\Model\ResourceModel\CheckOrderStatus');
    }
}
