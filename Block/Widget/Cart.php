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
        \Payflex\Gateway\Helper\PaymentUtil $paymentUtil,
        \Payflex\Gateway\Logger\PayflexLogger $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->_logger = $logger;
        $this->_paymentUtil = $paymentUtil;
        $this->_checkoutSession = $checkoutSession;
    }

    public function getCartWidgetHtml()
    {
        $quote = $this->_checkoutSession->getQuote();
        return $this->_paymentUtil->getWidgetHtml($quote->getGrandTotal());
    }
}
