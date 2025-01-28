<?php

namespace Payflex\Gateway\Block\Widget;

use Magento\Framework\View\Element\Template\Context;

class Cart extends \Magento\Framework\View\Element\Template
{

    /**
     * @var $_paymentUtil \Payflex\Gateway\Helper\PaymentUtil
     */
    protected $_paymentUtil;

    /**
     * @var $_checkoutSession \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    public function __construct(
        Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        array $data = [])
    {
        parent::__construct($context, $data);

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_paymentUtil = $objectManager->get("\Payflex\Gateway\Helper\PaymentUtil");
        $this->_checkoutSession = $checkoutSession;
        $this->_logger->info('Cart:'.__METHOD__);
    }

    public function getCartWidgetHtml()
    {
        $quote = $this->_checkoutSession->getQuote();
        return $this->_paymentUtil->getWidgetHtml($quote->getGrandTotal());
    }
}
