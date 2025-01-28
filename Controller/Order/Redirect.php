<?php
// reference:
// https://www.ashsmith.io/2014/12/simple-magento2-controller-module/
// http://www.webmull.com/magento-2-create-simple-hello-world-module/
// http://www.clounce.com/magento/a-very-basic-magento-2-module
// http://www.clounce.com/magento/a-very-basic-magento-2-module-with-parameterized-template

// custom module: http://magento.stackexchange.com/questions/54609/custom-module-not-working-in-magento-2

// http://devdocs.magento.com/guides/v2.0/frontend-dev-guide/bk-frontend-dev-guide.html

// http://stackoverflow.com/questions/32356635/blank-page-in-a-custom-module-magento-2-beta-merchant-version-1-0-0
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
