<?php
namespace Payflex\Gateway\Controller\Order;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Payflex\Gateway\Logger\PayflexLogger;

class Redirect extends Action
{
    /** @var PageFactory */
    private $_resultPageFactory;

    /** @var PayflexLogger */
    private $_logger;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        PayflexLogger $logger
    ) {
        parent::__construct($context);
        $this->_resultPageFactory = $resultPageFactory;
        $this->_logger = $logger;
    }

    public function execute()
    {
        $this->_logger->info(__METHOD__);
        $resultPage = $this->_resultPageFactory->create();
        $resultPage->getLayout()->initMessages();
        return $resultPage;
    }
}
