<?php
namespace Payflex\Gateway\Controller\Order;

use Magento\Framework\App\Action\Context;

use Magento\Sales\Model\Order;
abstract class CommonAction extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Payflex\Gateway\Logger\PayflexLogger
     */
    private $_logger;

    /**
     * @var \Payflex\Gateway\Helper\Communication
     */
    private $_communication;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $_checkoutSession;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private $_messageManager;

    /**
     * @var \Magento\Sales\Api\OrderManagementInterface
     */
    protected $orderManagement;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    private $orderSender;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    private $_invoiceService;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    private $invoiceSender;

    /**
     * @var \Magento\Quote\Model\QuoteFactory
     */
    protected $_quoteFactory;

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\Builder
     */
    protected $_transactionBuilder;

    /**
     * @var \Payflex\Gateway\Helper\Configuration
     */
    protected $_configHelper;

    /**
     * @var \Payflex\Gateway\Helper\PaymentUtil
     */
    private $paymentUtil;

    /**
     * @var \Magento\Sales\Model\Order
     */
    protected $_order;

    /**
     * CommonAction constructor.
     *
     * @param Context $context 
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);
        $this->_logger             = $this->_objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_communication      = $this->_objectManager->get("\Payflex\Gateway\Helper\Communication");
        $this->_storeManager       = $this->_objectManager->get("\Magento\Store\Model\StoreManagerInterface");
        $this->_checkoutSession    = $this->_objectManager->get("\Magento\Checkout\Model\Session");
        $this->_messageManager     = $this->_objectManager->get("\Magento\Framework\Message\ManagerInterface");
        $this->orderManagement     = $this->_objectManager->get("\Magento\Sales\Api\OrderManagementInterface");
        $this->orderSender         = $this->_objectManager->get("\Magento\Sales\Model\Order\Email\Sender\OrderSender");
        $this->_invoiceService     = $this->_objectManager->get("\Magento\Sales\Model\Service\InvoiceService");
        $this->_quoteFactory       = $this->_objectManager->get("\Magento\Quote\Model\QuoteFactory");
        $this->_transactionBuilder = $this->_objectManager->get("\Magento\Sales\Model\Order\Payment\Transaction\Builder");
        $this->_configHelper       = $this->_objectManager->get("\Payflex\Gateway\Helper\Configuration");
        $this->invoiceSender       = $this->_objectManager->get("\Magento\Sales\Model\Order\Email\Sender\InvoiceSender");

        $this->paymentUtil = $this->_objectManager->get("\Payflex\Gateway\Helper\PaymentUtil");

        $this->_logger->info(__METHOD__);
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
        return;
    }

    protected function _validateToken($payflexId, $orderToken,$orderIncrementId){
        $this->_logger->info(__METHOD__);
        $requestTokenManager = $this->_objectManager->create("\Payflex\Gateway\Model\RequestToken");
        $requestToken = $requestTokenManager->load($payflexId, "payflex_id");
        return $requestToken->getId() && $requestToken->getToken() == $orderToken && $requestToken->getOrderId() == $orderIncrementId;
    }

    /**
     * Technically the _handlePaymentResponseWithoutLock() is the main function to handle the payment response.
     * But we need to implement an order lock to prevent duplicate orders.
     */
    private function _handlePaymentResponse($success)
    {
        $this->_logger->info(__METHOD__);
        $orderIncrementId = $this->getRequest()->getParam('mage_order_id');
        $orderToken = $this->getRequest()->getParam('token');
        $payflexId = $this->getRequest()->getParam('orderId');

        if(!$this->_validateToken($payflexId, $orderToken,$orderIncrementId)) {
            $error = "The token and payment ID do not match at PayFlex side.";
            $this->_logger->info($error);
            $this->_redirectToCartPageWithError($error);
            return;
        }
        $this->_logger->info(__METHOD__ . " order:{$orderIncrementId} token:{$orderToken} success:{$success}");

        // Here we basically just need to run the _handlePaymentResponseWithoutLock() function, but before that,
        // to prevent duplicates, we need to do a complicated lock mechanism using a temporary file.
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
            $this->_checkoutSession->restoreQuote();
            $this->_logger->critical(__METHOD__ . "  " . "\n" . $e->getMessage() . $e->getTraceAsString());
            $this->_redirectToCartPageWithError("Failed to process the order, please contact support.");
        }
    }

    private function _handlePaymentResponseWithoutLock($success, $orderIncrementId, $payflexId)
    {
        $this->_logger->info(__METHOD__ . " order:{$orderIncrementId} payflexId:{$payflexId} success:{$success}");
        if (!$orderIncrementId) {
            $error = "The PayFlex response does not contain an order ID. Order failed.";
            $this->_logger->info($error);
            $this->_redirectToCartPageWithError($error);
            return;
        }
        $response = $this->_getTransactionStatus($payflexId,$orderIncrementId);
        if (!$response) {
            return;
        }
        $orderInfo = $this->getOrderByIncrementId($orderIncrementId);
        if(empty($orderInfo)){
            $error = "Failed to load order: {$orderIncrementId}";
            $this->_logger->critical($error);
            $this->_redirectToCartPageWithError($error);
            return;
        }
        if (!$success || in_array($response['orderStatus'], ['Declined', 'Abandoned']) && $response['merchantReference'] == $orderIncrementId) {
            $order_processing_status = $this->_configHelper->getPayflexNewOrderStatus($this->_storeManager->getStore()->getId());

            if($orderInfo->getStatus() != $order_processing_status)
            {
                if($orderInfo->canCancel())
                {
                    $orderInfo->setEmailSent(0);
                    $orderInfo->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);

                    $this->orderManagement->cancel($orderInfo->getId());
                    $orderInfo->addStatusHistoryComment('Payflex: Order has been cancelled. Order ID: '.$orderIncrementId);
                    $orderInfo->cancel();
                    $orderInfo->save();

                    $payment = $orderInfo->getPayment();
                    $this->savePaymentInfoForFailedPayment($payment);
                    $error = "Transaction has been cancelled or declined Order ID: ".$orderIncrementId;
                    $this->_logger->info($error);
                    $this->_checkoutSession->restoreQuote();
                    $this->_redirectToCartPageWithError($error);

                }
            }
            return;
        }

        return $this->_updateSuccessOrder($orderInfo, $response);
    }
    /**
     * This function updates the order with the successful payment response.
     * This is similar to the CRON function, but it is called directly after the payment is successful.
     */
    private function _updateSuccessOrder($order , $response){
        $this->_logger->info(__METHOD__);
        $orderId =  $order->getId();
        $quoteId = $order->getQuoteId();
        $this->_logger->critical('orderId='.$orderId);
        if (isset($response['orderStatus']) && $response['orderStatus'] == 'Approved' && $response['merchantReference'] == $order->getIncrementId()) {
            $quote = $this->paymentUtil->loadQuote($quoteId);
            if(!$quote)
            {
                $this->_redirectToCartPageWithError("Failed to load quote. Please try again.");
                return;
            }
            $payment = $quote->getPayment();

            $this->_logger->info(__METHOD__.' orderStatus : '.$response['orderStatus']);

            try {

                $this->paymentUtil->createTransaction( $order, $response );
                if ($order->canInvoice() && !$order->hasInvoices()) {

                    // Order is pending payment at this point.
                    $this->paymentUtil->generateInvoice( $order );

                }
              } catch ( \Exception $ex ) {
                  $this->_logger->error( $ex->getMessage() );
              }
            
             $this->_checkoutSession->setLoadInactive(false);
             $this->_logger->info(__METHOD__ . " placing order done lastOrderId". $orderId.
                                                                    " lastQuoteId:".$quoteId
                  );
     
             $this->_redirect("checkout/onepage/success", [
                 "_secure" => true
             ]);
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

        $info = $payment->getAdditionalInformation();
        $this->_logger->info(__METHOD__ . " info: ".var_export($info, true));
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
        $this->_logger->info(__METHOD__);

        unset($info["cartId"]);
        unset($info["guestEmail"]);
        unset($info["method_title"]);

        $this->_logger->info(__METHOD__ . " info: ".var_export($info, true));
        return $info;
    }

    private function _getTransactionStatus($payflexId,$orderIncrementId)
    {
        try{
            if(!$payflexId){
                throw new \Magento\Framework\Exception\NotFoundException(__('Can\'t find the initial PayFlex request ID.'));
            }
            $response = $this->_communication->getTransactionStatus($payflexId);
            if (!$response) { // defensive code. should never happen
                throw new \Magento\Framework\Exception\NotFoundException(__('Transaction status checking response format is incorrect.'));
            }
            if($response['orderStatus']  == 'Approved'){
                if($response['merchantReference'] != $orderIncrementId){
                    throw new \Magento\Framework\Exception\NotFoundException(__('MerchantReference is incorrect'));
                }
            }
        } catch (\Exception $ex) {
            $this->_logger->critical(__METHOD__ . " payflexId:{$payflexId} response format is incorrect");
            $this->_redirectToCartPageWithError("Failed to connect to PayFlex checking transaction status. Please try again later.");
            return false;
        }

        return $response;
    }

    private function _redirectToCartPageWithError($error)
    {
        $this->_logger->info(__METHOD__ . " error:{$error}");

        $this->_messageManager->addErrorMessage($error);
        $this->_redirect("checkout/cart");
    }
    
    public function getOrderByIncrementId( $incrementId )
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        return $objectManager->get( '\Magento\Sales\Model\Order' )->loadByIncrementId( $incrementId );
    }
}
