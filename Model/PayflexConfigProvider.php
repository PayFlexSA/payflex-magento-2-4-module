<?php
namespace Payflex\Gateway\Model;

use \Magento\Checkout\Model\ConfigProviderInterface;

// Invoked by Magento\Checkout\Block\Onepage::getCheckoutConfig
class PayflexConfigProvider implements ConfigProviderInterface
{

    /**
     *
     * @var \Payflex\Gateway\Logger\PayflexLogger
     */
    private $_logger;

    /**
     *
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;

    /**
     *
     * @var \Payflex\Gateway\Helper\Configuration
     */
    private $_configuration;

    public function __construct(\Magento\Framework\ObjectManagerInterface $objectManager)
    {
        $this->_objectManager = $objectManager;
        $this->_configuration = $this->_objectManager->get("\Payflex\Gateway\Helper\Configuration");
        $this->_logger = $this->_objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_logger->info(__METHOD__);
    }

    public function getConfig()
    {
        $this->_logger->info(__METHOD__);
        $session = $this->_objectManager->get('\Magento\Checkout\Model\Session');
        $quote = $session->getQuote();
        $quoteId = $quote->getId();
        $this->_logger->info(__METHOD__.' quoteId : '.$quoteId);
//        $customerSession = $this->_objectManager->get("\Magento\Customer\Model\Session");
        $paymentUtil = $this->_objectManager->get("\Payflex\Gateway\Helper\PaymentUtil");

        
        return [
            'payment' => [
                'payflex' => [
                    'redirectUrl' => $paymentUtil->buildRedirectUrl($quoteId),
                    'method' => \Payflex\Gateway\Model\Payment::Payflex_Gateway_CODE
                ]
            ]
        ];
    }
}
