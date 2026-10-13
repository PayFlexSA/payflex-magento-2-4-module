<?php
namespace Payflex\Gateway\Controller\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\OrderFactory;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\RequestTokenFactory;
use Payflex\Gateway\Service\OrderFinalizer;
use Payflex\Gateway\Service\OrderReconciler;

/**
 * Shared logic for the Payflex return controllers (Success / Fail). The order
 * status always comes from Payflex, not from which link the customer returned by.
 */
abstract class CommonAction extends Action
{
    /** @var PayflexLogger */
    protected $_logger;

    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var Configuration */
    private $configHelper;

    /** @var OrderFinalizer */
    private $orderFinalizer;

    /** @var OrderReconciler */
    private $orderReconciler;

    /** @var RequestTokenFactory */
    private $requestTokenFactory;

    /** @var OrderFactory */
    private $orderFactory;

    public function __construct(
        Context $context,
        PayflexLogger $logger,
        CheckoutSession $checkoutSession,
        Configuration $configHelper,
        OrderFinalizer $orderFinalizer,
        OrderReconciler $orderReconciler,
        RequestTokenFactory $requestTokenFactory,
        OrderFactory $orderFactory
    ) {
        parent::__construct($context);
        $this->_logger = $logger;
        $this->checkoutSession = $checkoutSession;
        $this->configHelper = $configHelper;
        $this->orderFinalizer = $orderFinalizer;
        $this->orderReconciler = $orderReconciler;
        $this->requestTokenFactory = $requestTokenFactory;
        $this->orderFactory = $orderFactory;
    }

    public function success()
    {
        $this->_logger->info(__METHOD__);
        $this->_handlePaymentResponse(true);
    }

    public function fail()
    {
        $this->_logger->info(__METHOD__);
        $this->_handlePaymentResponse(false);
    }

    protected function _validateToken($payflexId, $orderToken, $orderIncrementId)
    {
        $this->_logger->info(__METHOD__);
        $requestToken = $this->requestTokenFactory->create()->load($payflexId, "payflex_id");
        return $requestToken->getId()
            && $requestToken->getToken() == $orderToken
            && $requestToken->getOrderId() == $orderIncrementId;
    }

    /**
     * Verify the token Payflex sent back, then hand the order over to the
     * matching return handler.
     */
    private function _handlePaymentResponse($success)
    {
        $this->_logger->info(__METHOD__);
        $orderIncrementId = $this->getRequest()->getParam('mage_order_id');
        $orderToken = $this->getRequest()->getParam('token');
        $payflexId = $this->getRequest()->getParam('orderId');

        if (!$this->_validateToken($payflexId, $orderToken, $orderIncrementId)) {
            $error = "The token and payment ID do not match at Payflex side.";
            $this->_logger->info($error);
            $this->_redirectToCartPageWithError($error);
            return;
        }
        $this->_logger->info(__METHOD__ . " order:{$orderIncrementId} token:{$orderToken} success:{$success}");

        try {
            $this->_handleOrderReturn($success, $orderIncrementId, $payflexId);
        } catch (\Exception $e) {
            $this->checkoutSession->restoreQuote();
            $this->_logger->critical(__METHOD__ . "  " . "\n" . $e->getMessage() . $e->getTraceAsString());
            $this->_redirectToCartPageWithError("Failed to process the order, please contact support.");
        }
    }

    private function _handleOrderReturn($success, $orderIncrementId, $payflexId)
    {
        $this->_logger->info(__METHOD__ . " order:{$orderIncrementId} payflexId:{$payflexId} success:{$success}");

        if (!$orderIncrementId) {
            $error = "The Payflex response does not contain an order ID. Order failed.";
            $this->_logger->info($error);
            $this->_redirectToCartPageWithError($error);
            return;
        }

        $order = $this->getOrderByIncrementId($orderIncrementId);
        if (!$order->getId()) {
            $error = "Failed to load order: {$orderIncrementId}";
            $this->_logger->critical($error);
            $this->_redirectToCartPageWithError($error);
            return;
        }

        if ($success) {
            $this->_handleSuccessReturn($order, $payflexId);
            return;
        }

        $this->_handleCancelReturn($order, $payflexId);
    }

    /**
     * The customer came back through the Payflex confirm link. The order has
     * usually been finalised by the webhook already.
     */
    private function _handleSuccessReturn($order, $payflexId)
    {
        $orderIncrementId = $order->getIncrementId();

        if ($this->orderFinalizer->isFinalized($order)) {
            $this->_logger->info(__METHOD__ . " order {$orderIncrementId} was already finalised");
            $this->_redirectToCheckoutSuccess();
            return;
        }

        try {
            $action = $this->orderReconciler->reconcile($order, 'Payflex');
        } catch (\Exception $e) {
            $this->_logger->critical(__METHOD__ . " order:{$orderIncrementId} " . $e->getMessage());
            $this->_redirectToCartPageWithError("Failed to connect to Payflex checking transaction status. Please try again later.");
            return;
        }

        if ($action === OrderReconciler::RESULT_BUSY) {
            $this->_waitForOtherProcess($orderIncrementId);
            return;
        }

        if ($action === OrderFinalizer::RESULT_CAPTURED) {
            $this->_logger->info(__METHOD__ . " order {$orderIncrementId} captured, Payflex Order ID: {$payflexId}");
            $this->_redirectToCheckoutSuccess();
            return;
        }

        if ($action === OrderFinalizer::RESULT_NEEDS_REVIEW) {
            $this->_logger->critical(__METHOD__ . " order {$orderIncrementId} is paid but cancelled");
            $this->_redirectToCartPageWithError("Your payment went through but the order needs to be checked. Please contact support.");
            return;
        }

        $this->_returnToCartFor($order, $action);
    }

    /**
     * The customer came back through the Payflex cancel link. The visit is
     * recorded on the order, but only Payflex decides whether it is cancelled.
     */
    private function _handleCancelReturn($order, $payflexId)
    {
        $orderIncrementId = $order->getIncrementId();

        if (!$this->getRequest()->getParam('TriedTime')) {
            $order->addStatusHistoryComment((string) __(
                'Payflex: the customer returned to the store using the Payflex cancel link. Payflex Order ID: %1',
                $payflexId
            ))->setIsCustomerNotified(false)->save();
        }

        if ($this->orderFinalizer->isFinalized($order)) {
            $this->_noteCancelAfterPayment($order);
            $this->_redirectToCheckoutSuccess();
            return;
        }

        try {
            $action = $this->orderReconciler->reconcile($order, 'Payflex');
        } catch (\Exception $e) {
            $this->_logger->critical(__METHOD__ . " order:{$orderIncrementId} " . $e->getMessage());
            $action = OrderFinalizer::RESULT_NONE;
        }

        if ($action === OrderReconciler::RESULT_BUSY) {
            $this->_waitForOtherProcess($orderIncrementId);
            return;
        }

        if ($action === OrderFinalizer::RESULT_CAPTURED) {
            $this->_noteCancelAfterPayment($order);
            $this->_redirectToCheckoutSuccess();
            return;
        }

        $this->_returnToCartFor($order, $action);
    }

    /**
     * Send the customer back to their cart after a payment that did not
     * complete, with a message that matches what Payflex actually reported.
     */
    private function _returnToCartFor($order, $action)
    {
        $orderIncrementId = $order->getIncrementId();

        if ($action === OrderFinalizer::RESULT_CANCELLED) {
            $this->savePaymentInfoForFailedPayment($order->getPayment());
            $error = "Transaction has been cancelled or declined Order ID: " . $orderIncrementId;
        } else {
            $this->_logger->info(__METHOD__ . " order {$orderIncrementId} left pending, action:{$action}");
            $error = "Your payment was not completed. Your cart has been restored so you can try again.";
        }

        $this->checkoutSession->restoreQuote();
        $this->_redirectToCartPageWithError($error);
    }

    /**
     * Leave a trail on orders where the customer used the cancel link even
     * though the payment had already gone through.
     */
    private function _noteCancelAfterPayment($order)
    {
        $this->_logger->critical(__METHOD__ . " cancel link used on paid order {$order->getIncrementId()}");

        $order->addStatusHistoryComment((string) __(
            'Payflex: the payment for this order had already been approved, so the cancellation was ignored.'
            . ' The customer reached the cancel link after approving the payment.'
        ))->setIsCustomerNotified(false)->save();
    }

    private function _redirectToCheckoutSuccess()
    {
        $this->checkoutSession->setLoadInactive(false);
        $this->_redirect("checkout/onepage/success", ["_secure" => true]);
    }

    /**
     * Another process is reconciling this order, so re-run this action shortly
     * and let the customer land on whatever that process decided.
     */
    private function _waitForOtherProcess($orderIncrementId)
    {
        $action = $this->getRequest()->getActionName();
        $params = $this->getRequest()->getParams();

        $triedTime = 0;
        if (array_key_exists('TriedTime', $params)) {
            $triedTime = (int) $params['TriedTime'];
        }

        if ($triedTime > 40) { // 40 seconds should be enough
            $this->_logger->critical(__METHOD__ . " lock timeout. order:{$orderIncrementId} triedTime:{$triedTime}");
            $this->_redirectToCartPageWithError("Failed to process the order, please contact support.");
            return;
        }

        $params['TriedTime'] = $triedTime + 1;

        $this->_logger->info(__METHOD__ . " waiting for lock release. order:{$orderIncrementId} triedTime:{$triedTime}");
        sleep(1);
        $this->_forward($action, null, null, $params);
    }

    public function savePaymentInfoForSuccessfulPayment($payment, $response)
    {
        $this->_logger->info(__METHOD__);
        $info = $payment->getAdditionalInformation();
        $info = $this->_clearPaymentParameters($info);
        $info = array_merge($info, $response);

        $payment->unsAdditionalInformation();
        $payment->setAdditionalInformation($info);
        $payment->save();
    }

    public function savePaymentInfoForFailedPayment($payment)
    {
        $this->_logger->info(__METHOD__);
        $info = $payment->getAdditionalInformation();
        $info = $this->_clearPaymentParameters($info);

        $payment->unsAdditionalInformation();
        $payment->setAdditionalInformation($info);
        $payment->save();
    }

    private function _clearPaymentParameters($info)
    {
        unset($info["cartId"], $info["guestEmail"], $info["method_title"]);
        return $info;
    }

    private function _redirectToCartPageWithError($error)
    {
        $this->_logger->info(__METHOD__ . " error:{$error}");
        $this->messageManager->addErrorMessage($error);
        $this->_redirect("checkout/cart");
    }

    public function getOrderByIncrementId($incrementId)
    {
        return $this->orderFactory->create()->loadByIncrementId($incrementId);
    }
}
