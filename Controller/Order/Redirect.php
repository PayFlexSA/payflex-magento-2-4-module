<?php
namespace Payflex\Gateway\Controller\Order;

class Redirect extends \Magento\Framework\App\Action\Action
{
    /**
     *
     * @var \Magento\Framework\View\Result\PageFactory
     */
    private $_resultPageFactory;

    /**
     *
     * @var \Payflex\Gateway\Logger\PayflexLogger
     */
    private $_logger;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory
    )
    {
        $this->_resultPageFactory = $resultPageFactory;
        parent::__construct($context);
        $this->_logger = $this->_objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_logger->info(__METHOD__);
    }

    public function execute()
    {
        $this->_logger->info(__METHOD__);
        $resultPage = $this->_resultPageFactory->create();
        $resultPage->getLayout()->initMessages();
        return $resultPage;
    }
}
