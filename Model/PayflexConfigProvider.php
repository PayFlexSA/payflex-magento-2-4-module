<?php
namespace Payflex\Gateway\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Helper\PaymentUtil;
use Payflex\Gateway\Logger\PayflexLogger;

// Invoked by Magento\Checkout\Block\Onepage::getCheckoutConfig
class PayflexConfigProvider implements ConfigProviderInterface
{
    /** @var PayflexLogger */
    private $_logger;

    /** @var Configuration */
    private $_configuration;

    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var PaymentUtil */
    private $paymentUtil;

    public function __construct(
        Configuration $configuration,
        CheckoutSession $checkoutSession,
        PaymentUtil $paymentUtil,
        PayflexLogger $logger
    ) {
        $this->_configuration = $configuration;
        $this->checkoutSession = $checkoutSession;
        $this->paymentUtil = $paymentUtil;
        $this->_logger = $logger;
    }

    public function getConfig()
    {
        $quote = $this->checkoutSession->getQuote();
        $quoteId = $quote->getId();
        $this->_logger->info(__METHOD__ . ' quoteId : ' . $quoteId);

        return [
            'payment' => [
                'payflex' => [
                    'redirectUrl' => $this->paymentUtil->buildRedirectUrl($quoteId),
                    'method' => \Payflex\Gateway\Model\Payment::Payflex_Gateway_CODE
                ]
            ]
        ];
    }
}
