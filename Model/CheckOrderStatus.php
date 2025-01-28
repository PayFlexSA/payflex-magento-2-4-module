<?php
// http://www.mage-world.com/blog/how-to-use-model-and-collection-in-magento-2.html
// http://stackoverflow.com/questions/31983546/in-magento-2-what-is-the-correct-way-for-getmodel/31984198
// http://stackoverflow.com/questions/31920769/how-to-save-data-using-model-in-magento2
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
