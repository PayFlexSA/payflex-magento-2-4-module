<?php
namespace Payflex\Gateway\Controller\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\Order;
use Payflex\Gateway\Helper\Payflex\UrlCreator;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\Payment;
use Payflex\Gateway\Model\ResourceModel\RequestToken as RequestTokenResource;
use Payflex\Gateway\Service\OrderFinalizer;

/**
 * Creates a Payflex session for the order just placed and sends the customer to Payflex.
 */
class Redirect extends Action
{
    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var UrlCreator */
    private $urlCreator;

    /** @var RequestTokenResource */
    private $requestTokenResource;

    /** @var OrderFinalizer */
    private $orderFinalizer;

    /** @var PayflexLogger */
    private $_logger;

    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        UrlCreator $urlCreator,
        RequestTokenResource $requestTokenResource,
        OrderFinalizer $orderFinalizer,
        PayflexLogger $logger
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->urlCreator = $urlCreator;
        $this->requestTokenResource = $requestTokenResource;
        $this->orderFinalizer = $orderFinalizer;
        $this->_logger = $logger;
    }

    public function execute()
    {
        $this->_logger->info(__METHOD__);
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order->getId()) {
            $this->_logger->info(__METHOD__ . " no order in the checkout session");
            return $this->_redirectToCartWithError("Your order could not be found. Please try again.");
        }

        $orderIncrementId = $order->getIncrementId();
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== Payment::Payflex_Gateway_CODE) {
            $this->_logger->info(__METHOD__ . " order {$orderIncrementId} is not a Payflex order");
            return $this->_redirectToCartWithError("Your order could not be found. Please try again.");
        }

        if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
            $this->_logger->info(__METHOD__ . " order {$orderIncrementId} is not pending payment, state:" . $order->getState());
            return $this->_redirectToCartWithError("This order can no longer be paid with Payflex.");
        }

        $url = '';
        try {
            $url = $this->urlCreator->createUrl($order);
        } catch (\Exception $e) {
            $this->_logger->critical(__METHOD__ . " order:{$orderIncrementId} " . $e->getMessage());
        }

        if (!$url) {
            return $this->_handleSessionFailure($order);
        }

        $this->_logger->info(__METHOD__ . " order:{$orderIncrementId} redirectUrl:{$url}");
        return $this->resultRedirectFactory->create()->setUrl($url);
    }

    /**
     * Cancel the order if it never reached Payflex, then return the customer to their cart.
     */
    private function _handleSessionFailure(Order $order)
    {
        $orderIncrementId = $order->getIncrementId();
        $error = "We could not connect to Payflex. Your cart has been restored so you can try again.";

        $payflexIds = $this->requestTokenResource->getPayflexIds($orderIncrementId);
        if (!empty($payflexIds)) {
            $this->_logger->critical(__METHOD__ . " order {$orderIncrementId} left pending, earlier Payflex order(s): "
                . implode(', ', $payflexIds));
            $this->checkoutSession->restoreQuote();
            return $this->_redirectToCartWithError($error);
        }

        try {
            $this->orderFinalizer->cancel($order, (string) __(
                'Payflex: the order was cancelled because a Payflex payment session could not be created.'
            ));
        } catch (\Exception $e) {
            $this->_logger->critical(__METHOD__ . " failed to cancel order {$orderIncrementId}: " . $e->getMessage());
        }

        $this->checkoutSession->restoreQuote();
        return $this->_redirectToCartWithError($error);
    }

    private function _redirectToCartWithError($error)
    {
        $this->_logger->info(__METHOD__ . " error:{$error}");
        $this->messageManager->addErrorMessage($error);
        return $this->resultRedirectFactory->create()->setPath('checkout/cart');
    }
}
