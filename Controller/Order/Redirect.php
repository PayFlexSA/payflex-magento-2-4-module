<?php
namespace Payflex\Gateway\Controller\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Payflex\Gateway\Helper\Payflex\UrlCreator;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\Payment;
use Payflex\Gateway\Model\ResourceModel\RequestToken as RequestTokenResource;
use Payflex\Gateway\Service\OrderFinalizer;
use Payflex\Gateway\Service\OrderReconciler;

/**
 * Creates a Payflex session for the order just placed and sends the customer to Payflex.
 */
class Redirect extends Action
{
    /** Seconds to wait for another process that is updating the order. */
    const LOCK_TIMEOUT = 5;

    /** Times to retry when the order is still locked. */
    const MAX_LOCK_TRIES = 3;

    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var UrlCreator */
    private $urlCreator;

    /** @var RequestTokenResource */
    private $requestTokenResource;

    /** @var OrderFinalizer */
    private $orderFinalizer;

    /** @var OrderReconciler */
    private $orderReconciler;

    /** @var OrderFactory */
    private $orderFactory;

    /** @var PayflexLogger */
    private $_logger;

    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        UrlCreator $urlCreator,
        RequestTokenResource $requestTokenResource,
        OrderFinalizer $orderFinalizer,
        OrderReconciler $orderReconciler,
        OrderFactory $orderFactory,
        PayflexLogger $logger
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->urlCreator = $urlCreator;
        $this->requestTokenResource = $requestTokenResource;
        $this->orderFinalizer = $orderFinalizer;
        $this->orderReconciler = $orderReconciler;
        $this->orderFactory = $orderFactory;
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
        if (!$this->orderReconciler->lockOrder($orderIncrementId, self::LOCK_TIMEOUT)) {
            $this->_logger->info(__METHOD__ . " order {$orderIncrementId} is locked by another process");
            return $this->_waitForOtherProcess($orderIncrementId);
        }

        try {
            $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
            return $this->_redirectToPayflex($order);
        } finally {
            $this->orderReconciler->unlockOrder($orderIncrementId);
        }
    }

    /**
     * Create a Payflex session for a pending Payflex order and send the customer to it.
     */
    private function _redirectToPayflex(Order $order)
    {
        $orderIncrementId = $order->getIncrementId();
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== Payment::Payflex_Gateway_CODE) {
            $this->_logger->info(__METHOD__ . " order {$orderIncrementId} is not a Payflex order");
            return $this->_redirectToCartWithError("Your order could not be found. Please try again.");
        }

        if ($this->orderFinalizer->isFinalized($order)) {
            return $this->_redirectForFinalizedOrder($order);
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

    /**
     * The order was already paid, so show the success page, or explain that it
     * needs to be checked. The cart is not restored either way.
     */
    private function _redirectForFinalizedOrder(Order $order)
    {
        if ($order->isPaymentReview() || $order->isCanceled()) {
            $this->_logger->critical(__METHOD__ . " order {$order->getIncrementId()} is paid but needs review");
            return $this->_redirectToCartWithError("Your payment went through but the order needs to be checked. Please contact support.");
        }

        $this->_logger->info(__METHOD__ . " order {$order->getIncrementId()} is already paid");
        $this->checkoutSession->setLoadInactive(false);
        return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success', ['_secure' => true]);
    }

    /**
     * Another process is updating the order, so run this action again shortly.
     * Gives up after MAX_LOCK_TRIES without cancelling or restoring anything.
     */
    private function _waitForOtherProcess($orderIncrementId)
    {
        $params = $this->getRequest()->getParams();

        $triedTime = 0;
        if (array_key_exists('TriedTime', $params)) {
            $triedTime = (int) $params['TriedTime'];
        }

        if ($triedTime >= self::MAX_LOCK_TRIES) {
            $this->_logger->critical(__METHOD__ . " lock timeout. order:{$orderIncrementId} triedTime:{$triedTime}");
            return $this->_redirectToCartWithError("We could not start your Payflex payment just now. Please try again in a moment.");
        }

        $params['TriedTime'] = $triedTime + 1;

        $this->_logger->info(__METHOD__ . " waiting for lock release. order:{$orderIncrementId} triedTime:{$triedTime}");
        sleep(1);
        $this->_forward($this->getRequest()->getActionName(), null, null, $params);
        return null;
    }

    private function _redirectToCartWithError($error)
    {
        $this->_logger->info(__METHOD__ . " error:{$error}");
        $this->messageManager->addErrorMessage($error);
        return $this->resultRedirectFactory->create()->setPath('checkout/cart');
    }
}
