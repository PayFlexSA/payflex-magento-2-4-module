<?php
namespace Payflex\Gateway\Controller\Order;

use \Magento\Framework\App\Action\Context;

class Fail extends CommonAction
{
    /**
     *
     * @var \Payflex\Gateway\Logger\PayflexLogger
     */
    private $_logger;

    public function __construct(Context $context)
    {
        parent::__construct($context);
        $this->_logger = $this->_objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_logger->info(__METHOD__);
    }

    public function execute()
    {
        $this->_logger->info(__METHOD__);
        $this->fail();
    }
}
