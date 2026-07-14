<?php
namespace Payflex\Gateway\Model\Api;

use Magento\Framework\Exception\State\InvalidTransitionException;
use Payflex\Gateway\Helper\Payflex\UrlCreator;
use Payflex\Gateway\Logger\PayflexLogger;

/**
 * Entry point for starting a Payflex session: applies the payment method to the
 * quote (via ApiCommonHelper) and returns the hosted-checkout redirect URL.
 */
class ApiPayflexHelper
{
    /** @var ApiCommonHelper */
    private $_apiCommonHelper;

    /** @var UrlCreator */
    private $_payflexUrlCreator;

    /** @var PayflexLogger */
    private $_logger;

    public function __construct(
        ApiCommonHelper $apiCommonHelper,
        UrlCreator $payflexUrlCreator,
        PayflexLogger $logger
    ) {
        $this->_apiCommonHelper = $apiCommonHelper;
        $this->_payflexUrlCreator = $payflexUrlCreator;
        $this->_logger = $logger;
    }

    public function createUrlForCustomer($quoteId, \Magento\Quote\Api\Data\PaymentInterface $method)
    {
        $this->_logger->info(__METHOD__ . " quoteId:{$quoteId}");

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->_apiCommonHelper->setPaymentForLoggedinCustomer($quoteId, $method);

        return $this->_createUrl($quote);
    }

    public function createUrlForGuest($cartId, $email, \Magento\Quote\Api\Data\PaymentInterface $method)
    {
        $this->_logger->info(__METHOD__ . " cartId:{$cartId}");

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->_apiCommonHelper->setPaymentForGuest($cartId, $email, $method);

        return $this->_createUrl($quote);
    }

    private function _createUrl(\Magento\Quote\Model\Quote $quote)
    {
        $url = $this->_payflexUrlCreator->CreateUrl($quote);
        if (!isset($url) || empty($url)) {
            $quoteId = $quote->getId();
            $this->_logger->critical(__METHOD__ . " Failed to create transaction quoteId:{$quoteId}");
            throw new InvalidTransitionException(__('Failed to create transaction, CreateUrl() returned nothing'));
        }

        $this->_logger->info(__METHOD__ . " redirectUrl:{$url}");
        return $url;
    }
}
