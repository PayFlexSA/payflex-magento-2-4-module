<?php
namespace Payflex\Gateway\Model\Api;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\PaymentMethodManagement;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\QuoteValidator;
use Payflex\Gateway\Logger\PayflexLogger;

/**
 * Applies the Payflex payment method to a quote (guest or logged-in), validates
 * it and reserves an order id ahead of creating the Payflex session.
 */
class ApiCommonHelper
{
    /** @var QuoteIdMaskFactory */
    private $_quoteIdMaskFactory;

    /** @var CartRepositoryInterface */
    private $_quoteRepository;

    /** @var PaymentMethodManagement */
    private $_paymentMethodManagement;

    /** @var QuoteValidator */
    private $_quoteValidator;

    /** @var CheckoutSession */
    private $_checkoutSession;

    /** @var PayflexLogger */
    private $_logger;

    public function __construct(
        QuoteIdMaskFactory $quoteIdMaskFactory,
        PaymentMethodManagement $paymentMethodManagement,
        CartRepositoryInterface $quoteRepository,
        QuoteValidator $quoteValidator,
        CheckoutSession $checkoutSession,
        PayflexLogger $logger
    ) {
        $this->_quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->_paymentMethodManagement = $paymentMethodManagement;
        $this->_quoteRepository = $quoteRepository;
        $this->_quoteValidator = $quoteValidator;
        $this->_checkoutSession = $checkoutSession;
        $this->_logger = $logger;
    }

    public function setPaymentForLoggedinCustomer($quoteId, \Magento\Quote\Api\Data\PaymentInterface $method)
    {
        $this->_logger->info(__METHOD__ . " quoteId:{$quoteId}");
        $this->_paymentMethodManagement->set($quoteId, $method);

        $quote = $this->_quoteRepository->get($quoteId);

        $this->_quoteValidator->validateBeforeSubmit($quote); // ensure all the data is correct

        $quote->reserveOrderId();
        $this->_quoteRepository->save($quote);
        $this->_logger->info(__METHOD__ . " getReservedOrderId:" . $quote->getReservedOrderId());
        $this->_checkoutSession->setData('last_real_order_id', $quote->getReservedOrderId());

        return $quote;
    }

    public function setPaymentForGuest($cartId, $email, \Magento\Quote\Api\Data\PaymentInterface $method)
    {
        $this->_logger->info(__METHOD__ . " cartId:{$cartId}");
        $quoteIdMask = $this->_quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        $quoteId = $quoteIdMask->getQuoteId();

        $this->_logger->info(__METHOD__ . " cartId:{$cartId}  quoteId:{$quoteId}");

        $this->_paymentMethodManagement->set($quoteId, $method);
        $quote = $this->_quoteRepository->get($quoteId);
        $quote->getBillingAddress()->setEmail($email);

        $this->_quoteValidator->validateBeforeSubmit($quote); // ensure all the data is correct

        $quote->setCustomerIsGuest(true);
        $quote->reserveOrderId();
        $this->_quoteRepository->save($quote);
        $this->_logger->info(__METHOD__ . " getReservedOrderId:" . $quote->getReservedOrderId());
        $this->_checkoutSession->setData('last_real_order_id', $quote->getReservedOrderId());
        $payment = $quote->getPayment();
        $info = $payment->getAdditionalInformation();
        if (isset($info["cartId"]) && $info["cartId"] != $cartId) {
            // Some PaymentInterface implementations don't carry the JS-supplied
            // data through; recover the cart id / guest email here.
            $this->_logger->info(__METHOD__ . " Unexpected behavior! cartId set incorrectly. PaymentInterface.class:" . get_class($method));
            $info["cartId"] = $cartId;
            $info["guestEmail"] = $email;

            $payment->setAdditionalInformation($info);
            $payment->save();
        }

        return $quote;
    }
}
