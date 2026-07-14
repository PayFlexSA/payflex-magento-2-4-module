<?php
namespace Payflex\Gateway\Controller\Order;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Payflex\Gateway\Logger\PayflexLogger;

class Error extends Action
{
    /** @var PageFactory */
    private $resultPageFactory;

    /** @var PayflexLogger */
    private $_logger;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        PayflexLogger $logger
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->_logger = $logger;
    }

    public function execute()
    {
        $this->_logger->info(__METHOD__);
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getLayout()->initMessages();
        return $resultPage;
    }
}
