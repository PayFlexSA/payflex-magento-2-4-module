<?php
namespace Payflex\Gateway\Controller\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Helper\PaymentUtil;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\RequestTokenFactory;
use Payflex\Gateway\Service\OrderFinalizer;

/**
 * Shared logic for the Payflex return controllers (Success / Fail). Verifies the
 * returned token against the stored request, re-checks the live Payflex status
 * server-to-server, then finalises or cancels the order via {@see OrderFinalizer}.
 */
abstract class CommonAction extends Action
{
    /** @var PayflexLogger */
    protected $_logger;

    /** @var Communication */
    private $communication;

    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var Configuration */
    private $configHelper;

    /** @var PaymentUtil */
    private $paymentUtil;

    /** @var OrderFinalizer */
    private $orderFinalizer;

    /** @var RequestTokenFactory */
    private $requestTokenFactory;

    /** @var OrderFactory */
    private $orderFactory;

    public function __construct(
        Context $context,
        PayflexLogger $logger,
        Communication $communication,
        CheckoutSession $checkoutSession,
        Configuration $configHelper,
        PaymentUtil $paymentUtil,
        OrderFinalizer $orderFinalizer,
        RequestTokenFactory $requestTokenFactory,
        OrderFactory $orderFactory
    ) {
        parent::__construct($context);
        $this->_logger = $logger;
        $this->communication = $communication;
        $this->checkoutSession = $checkoutSession;
        $this->configHelper = $configHelper;
        $this->paymentUtil = $paymentUtil;
        $this->orderFinalizer = $orderFinalizer;
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
     * Handle the return, guarding against duplicate concurrent processing with a
     * file lock. The real work happens in _handlePaymentResponseWithoutLock().
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

        $lockFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'order_' . md5($orderToken) . '.lock';
        try {
            $lockFile = fopen($lockFilePath, 'c');
            if (!$lockFile || !flock($lockFile, LOCK_EX | LOCK_NB)) {
                $action = $this->getRequest()->getActionName();
                $params = $this->getRequest()->getParams();
                $triedTime = 0;
                if (array_key_exists('TriedTime', $params)) {
                    $triedTime = $params['TriedTime'];
                }
                if ($triedTime > 40) { // 40 seconds should be enough
                    $this->_redirectToCartPageWithError("Failed to process the order, please contact support.");
                    $this->_logger->critical(__METHOD__ . " lock timeout. order:{$orderIncrementId} token:{$orderToken} success:{$success} triedTime:{$triedTime}");
                    return;
                }

                $params['TriedTime'] = $triedTime + 1;

                $this->_logger->info(__METHOD__ . " redirecting to self, wait for lock release. order:{$orderIncrementId} token:{$orderToken} success:{$success} triedTime:{$triedTime}");
                sleep(1); // wait for sometime about lock release
                return $this->_forward($action, null, null, $params);
            }

            $this->_handlePaymentResponseWithoutLock($success, $orderIncrementId, $payflexId);
            flock($lockFile, LOCK_UN);
            fclose($lockFile);
            unlink($lockFilePath);
        } catch (\Exception $e) {
            if (isset($lockFile)) {
                flock($lockFile, LOCK_UN);
                fclose($lockFile);
                unlink($lockFilePath);
            }
            $this->checkoutSession->restoreQuote();
            $this->_logger->critical(__METHOD__ . "  " . "\n" . $e->getMessage() . $e->getTraceAsString());
            $this->_redirectToCartPageWithError("Failed to process the order, please contact support.");
        }
    }

    private function _handlePaymentResponseWithoutLock($success, $orderIncrementId, $payflexId)
    {
        $this->_logger->info(__METHOD__ . " order:{$orderIncrementId} payflexId:{$payflexId} success:{$success}");
        if (!$orderIncrementId) {
            $error = "The Payflex response does not contain an order ID. Order failed.";
            $this->_logger->info($error);
            $this->_redirectToCartPageWithError($error);
            return;
        }
        $response = $this->_getTransactionStatus($payflexId, $orderIncrementId);
        if (!$response) {
            return;
        }
        $order = $this->getOrderByIncrementId($orderIncrementId);
        if (empty($order)) {
            $error = "Failed to load order: {$orderIncrementId}";
            $this->_logger->critical($error);
            $this->_redirectToCartPageWithError($error);
            return;
        }
        if (!$success || in_array($response['orderStatus'], ['Declined', 'Abandoned']) && $response['merchantReference'] == $orderIncrementId) {
            if ($this->orderFinalizer->cancel($order, 'Payflex: Order has been cancelled. Order ID: ' . $orderIncrementId)) {
                $this->savePaymentInfoForFailedPayment($order->getPayment());
                $error = "Transaction has been cancelled or declined Order ID: " . $orderIncrementId;
                $this->_logger->info($error);
                $this->checkoutSession->restoreQuote();
                $this->_redirectToCartPageWithError($error);
            }
            return;
        }

        return $this->_updateSuccessOrder($order, $response);
    }

    /**
     * Finalise a successfully-paid order (capture + invoice) and redirect the
     * shopper to the standard checkout success page.
     */
    private function _updateSuccessOrder($order, $response)
    {
        $this->_logger->info(__METHOD__);
        $orderId = $order->getId();
        $quoteId = $order->getQuoteId();

        if (isset($response['orderStatus']) && $response['orderStatus'] == 'Approved' && $response['merchantReference'] == $order->getIncrementId()) {
            $quote = $this->paymentUtil->loadQuote($quoteId);
            if (!$quote) {
                $this->_redirectToCartPageWithError("Failed to load quote. Please try again.");
                return;
            }

            try {
                $this->orderFinalizer->capture($order, $response);
            } catch (\Exception $ex) {
                $this->_logger->error($ex->getMessage());
            }

            $this->checkoutSession->setLoadInactive(false);
            $this->_logger->info(__METHOD__ . " placing order done lastOrderId" . $orderId . " lastQuoteId:" . $quoteId);

            $this->_redirect("checkout/onepage/success", ["_secure" => true]);
            return;
        }
        throw new \Magento\Framework\Exception\PaymentException(__('Payment failed. Order was not placed.'));
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

    private function _getTransactionStatus($payflexId, $orderIncrementId)
    {
        try {
            if (!$payflexId) {
                throw new \Magento\Framework\Exception\NotFoundException(__('Can\'t find the initial Payflex request ID.'));
            }
            $response = $this->communication->getTransactionStatus($payflexId);
            if (!$response) { // defensive code. should never happen
                throw new \Magento\Framework\Exception\NotFoundException(__('Transaction status checking response format is incorrect.'));
            }
            if ($response['orderStatus'] == 'Approved') {
                if ($response['merchantReference'] != $orderIncrementId) {
                    throw new \Magento\Framework\Exception\NotFoundException(__('MerchantReference is incorrect'));
                }
            }
        } catch (\Exception $ex) {
            $this->_logger->critical(__METHOD__ . " payflexId:{$payflexId} response format is incorrect");
            $this->_redirectToCartPageWithError("Failed to connect to Payflex checking transaction status. Please try again later.");
            return false;
        }

        return $response;
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
