<?php
namespace Payflex\Gateway\Controller\Order;

use Magento\Framework\App\Action\Context;

use Magento\Sales\Model\Order;
abstract class CommonAction extends \Magento\Framework\App\Action\Action
{
    /**
     *
     * @var \Magento\Checkout\Model\Session
     */
    private $_checkoutSession;

    /**
     *
     * @var \Payflex\Gateway\Helper\Communication
     */
    private $_communication;

    /**
     *
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private $_messageManager;

    /**
     *
     * @var \Payflex\Gateway\Logger\PayflexLogger
     */
    private $_logger;
    
    protected $orderManagement;
    private $OrderSender;
    private $_invoiceService;
    private $invoiceSender;
    

    /**
     * @var  \Magento\Sales\Model\Order $_order
     */
    protected $_order;
    protected $_quoteFactory;
     /**
     * @var Magento\Sales\Model\Order\Payment\Transaction\Builder $_transactionBuilder
     */
    protected $_transactionBuilder;
    /**
     * @var \Payflex\Gateway\Helper\Configuration
     */
    protected $_configHelper;
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    public function __construct(Context $context)
    {
        parent::__construct($context);
        $this->_logger = $this->_objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_communication = $this->_objectManager->get("\Payflex\Gateway\Helper\Communication");
        $this->_storeManager =  $this->_objectManager->get("\Magento\Store\Model\StoreManagerInterface");
        $this->_checkoutSession = $this->_objectManager->get("\Magento\Checkout\Model\Session");
        $this->_messageManager = $this->_objectManager->get("\Magento\Framework\Message\ManagerInterface");
        $this->orderManagement = $this->_objectManager->get("\Magento\Sales\Api\OrderManagementInterface");
        $this->OrderSender  =  $this->_objectManager->get("\Magento\Sales\Model\Order\Email\Sender\OrderSender");
        $this->_invoiceService =  $this->_objectManager->get("\Magento\Sales\Model\Service\InvoiceService");
        $this->_quoteFactory =  $this->_objectManager->get("\Magento\Quote\Model\QuoteFactory");
        $this->_transactionBuilder = $this->_objectManager->get("\Magento\Sales\Model\Order\Payment\Transaction\Builder");
        $this->_configHelper = $this->_objectManager->get("\Payflex\Gateway\Helper\Configuration");
        $this->invoiceSender = $this->_objectManager->get("\Magento\Sales\Model\Order\Email\Sender\InvoiceSender");

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
            if($orderInfo->getStatus() != Order::STATE_PROCESSING){       
                $orderInfo->setEmailSent(0);
                $orderInfo->setState(Order::STATE_CANCELED)
                ->setStatus(Order::STATE_CANCELED);
                if($orderInfo->canCancel()) {
                    $this->orderManagement->cancel($orderInfo->getId());
                    $orderInfo->addStatusHistoryComment('Transaction has been cancelled or declined from payflex window')->save();
                    $orderInfo->cancel();
                    $orderInfo->save();
                }
            }
            $payment = $orderInfo->getPayment();
            $this->_savePaymentInfoForFailedPayment($payment);
            $error = "Transaction has been cancelled or declined";
            $this->_logger->info($error);
            $this->_checkoutSession->restoreQuote();
            $this->_redirectToCartPageWithError($error);
            return;
        }

        return $this->_updateSuccessOrder($orderInfo, $response);
    }
    private function _updateSuccessOrder($order , $response){
        $this->_logger->info(__METHOD__);
        $orderId =  $order->getId();
        $quoteId = $order->getQuoteId();
        $this->_logger->critical('orderId='.$orderId);
        if (isset($response['orderStatus']) && $response['orderStatus'] == 'Approved' && $response['merchantReference'] == $order->getIncrementId()) {
            $quote = $this->_loadQuote($quoteId);
            $payment = $quote->getPayment();

            $this->_logger->info(__METHOD__.' orderStatus : '.$response['orderStatus']);

            try {

                $this->createTransaction( $order, $response );
                if ($order->canInvoice() && !$order->hasInvoices()) {

                    // Order is pending payment at this point.
                    $this->generateInvoice( $order );

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
    public function generateInvoice( $order )
    {
        $storeId = $this->_storeManager->getStore()->getId();
        $order_successful_email = $this->_configHelper->getOrderEmail($storeId);
        
        $state  = $this->_configHelper->getPayflexNewOrderState($this->_storeManager->getStore()->getId());
        $status = $this->_configHelper->getPayflexNewOrderStatus($this->_storeManager->getStore()->getId());


        if ( $order_successful_email != '0' ) {
            $this->OrderSender->send( $order );
            $this->_logger->info('CommonAction:'.__METHOD__.'Order Success Email Sent');
            $order->addStatusHistoryComment( __( 'Notified customer about order #%1.', $order->getId() ) )->setIsCustomerNotified( true )->save();
        }
        // Capture invoice when payment is successfull
        $invoice = $this->_invoiceService->prepareInvoice( $order );

        $invoice->setRequestedCaptureCase( \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE );
        $invoice->register();
        $this->_logger->info('CommonAction:'.__METHOD__.'invoice registred');
        // Save the invoice to the order


        // Set order status to custom status
        $invoice_order = $invoice->getOrder();
        
        $invoice_order->setStatus( $status );
        $invoice_order->setState( $state );
        $invoice_order->addStatusHistoryComment('Payment confirmed, Payflex Transaction ID: '.$invoice->getTransactionId(), $invoice_order->getStatus());
        $invoice_order->save();
        
        $transaction = $this->_objectManager->create( 'Magento\Framework\DB\Transaction' )
            ->addObject( $invoice )
            ->addObject( $invoice_order );

        // Payment confirmed. 
        $transaction->save();

        $this->_logger->info('Transaction Saved');
        // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
        $send_invoice_email = $this->_configHelper->getInvoiceEmail($storeId);
        if ( $send_invoice_email != '0' ) {
            $this->invoiceSender->send( $invoice );
            $this->_logger->info(__METHOD__.'Invoice Email Sent');
            $order->addStatusHistoryComment( __( 'Notified customer about invoice #%1.', $invoice->getId() ) )->setIsCustomerNotified( true )->save();
        }
    }
    private function createTransaction( $order = null, $paymentData = array() )
    {
        $this->_logger->info(__METHOD__ );
        try {
            if ( $paymentData['orderStatus'] != 'Approved' && $paymentData['merchantReference'] != $order->getIncrementId()) {
                $this->_logger->info(__METHOD__.': Order Mismatched');
                return false;
            }
            // Get payment object from order object
            $payment = $order->getPayment();
            $payment->setLastTransId( $paymentData['orderId'] )
                ->setTransactionId( $paymentData['orderId'] ) ;
                // ->setAdditionalInformation($paymentData)  ;
            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $message = __( 'The authorized amount is %1.', $formatedPrice );
            // Get the object of builder class
            $trans       = $this->_transactionBuilder;

            $transaction = $trans->setPayment( $payment )
                ->setOrder( $order )
                ->setTransactionId( $paymentData['orderId'] )
                ->setAdditionalInformation($paymentData)
                ->setFailSafe( true )
            // Build method creates the transaction and returns the object
                ->build( \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE );

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );
            $payment->setParentTransactionId( null );
            $payment->save();

            return $transaction->save()->getTransactionId();
        } catch ( \Exception $e ) {

            $this->_logger->error( $e->getMessage() );
        }
    }

    private function _loadQuote($quoteId)
    {
        $this->_logger->info(__METHOD__ . " QuoteId:{$quoteId}");

        $quote = $this->_quoteFactory->create()->loadByIdWithoutStore($quoteId);
        if (!$quote->getId()) {
            $error = "Failed to load quote : {$quoteId}";
            $this->_logger->critical($error);
            $this->_redirectToCartPageWithError($error);
            return null;
        }
        return $quote;
    }

    private function _savePaymentInfoForSuccessfulPayment($payment, $response)
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

    private function _savePaymentInfoForFailedPayment($payment)
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
