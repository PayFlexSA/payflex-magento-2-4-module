<?php
namespace Payflex\Gateway\Helper;

use Magento\Store\Model\StoreManagerInterface as StoreManager;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Framework\App\ObjectManager as ObjectManager;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\Order;


class PaymentUtil extends AbstractHelper
{
    
    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;

    /**
     * @var \Payflex\Gateway\Helper\Communication
     */
    protected $_communicationHelper;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    public $invoiceSender;

    /**
     * @var \Payflex\Gateway\Helper\Configuration
     */
    protected $_configHelper;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    protected $_orderCollectionFactory;

    /**
     * @var \Magento\Quote\Model\QuoteFactory
     */
    protected $_quoteFactory;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    private $OrderSender;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    private $_invoiceService;

    /**
     * @var \Magento\Sales\Api\OrderManagementInterface
     */
    public $orderManagement;

    /**
    * @var Magento\Sales\Model\Order\Payment\Transaction\Builder $transactionBuilder
    */
    private $transactionBuilder;

    /**
     * @var \Payflex\Gateway\Logger\PayflexLogger
     */
    protected $_logger;
    
    public function __construct( Context $context )
    {
        parent::__construct($context);
        
        $this->_objectManager          = ObjectManager::getInstance();
        $this->storeManager            = $this->_objectManager->get("Magento\Store\Model\StoreManagerInterface");
        $this->_orderCollectionFactory = $this->_objectManager->get("Magento\Sales\Model\ResourceModel\Order\CollectionFactory");
        $this->_logger                 = $this->_objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_communicationHelper    = $this->_objectManager->get("\Payflex\Gateway\Helper\Communication");
        $this->_configHelper           = $this->_objectManager->get("\Payflex\Gateway\Helper\Configuration");
        $this->orderManagement         = $this->_objectManager->get("\Magento\Sales\Api\OrderManagementInterface");
        $this->_quoteFactory           = $this->_objectManager->get("\Magento\Quote\Model\QuoteFactory");
        $this->orderRepository         = $this->_objectManager->get("\Magento\Sales\Api\OrderRepositoryInterface");
        $this->OrderSender             = $this->_objectManager->get("\Magento\Sales\Model\Order\Email\Sender\OrderSender");
        $this->_invoiceService         = $this->_objectManager->get("\Magento\Sales\Model\Service\InvoiceService");
        $this->invoiceSender           = $this->_objectManager->get("\Magento\Sales\Model\Order\Email\Sender\InvoiceSender");
        $this->transactionBuilder      = $this->_objectManager->get("\Magento\Sales\Model\Order\Payment\Transaction\Builder");

        $this->_logger->info(__METHOD__);
    }
    
    public function buildRedirectUrl()
    {
        $this->_logger->info(__METHOD__);
        $urlManager = $this->_objectManager->get('\Magento\Framework\Url');
        $url        = $urlManager->getUrl('payflex/order/redirect', ['_secure' => true]);
        
        $this->_logger->info(__METHOD__ . " url: {$url} ");
        return $url;
    }
    
    public function saveInvalidRefundResponse($payment, $responseText)
    {
        $this->_logger->info(__METHOD__ . " responseText:{$responseText}");
        $info = [
            "Error" => $responseText,
        ];
        $payment->setAdditionalInformation(date("Y-m-d H:i:s"), json_encode($info));
        $payment->save();
        return $info;
    }
    
    public function savePayflexRefundResponse($payment, $responseBody)
    {
        $this->_logger->info(__METHOD__ . " responseBody:{$responseBody}");
        $response = json_decode($responseBody, true);
        if (isset($response['id'])) {
            $payment->setTransactionId($response['id']);
        }
        $payment->setAdditionalInformation(date("Y-m-d H:i:s"), $responseBody);
        $payment->save();
        
        return $response;
    }
    
    public function loadOrderById($orderId)
    {
        $this->_logger->info(__METHOD__ . " orderId:{$orderId}");
        
        $orderManager = $this->_objectManager->get('Magento\Sales\Model\Order');
        $order = $orderManager->loadByAttribute("entity_id", $orderId);
        $orderIncrementId = $order->getIncrementId();
        $this->_logger->info(__METHOD__ . " orderIncrementId:{$orderIncrementId}");
        if (!isset($orderIncrementId)) {
            return null;
        }
        return $order;
    }
    
    public function loadCustomerInfo($order)
    {
        $customerId = $order->getCustomerId();
        $this->_logger->info(__METHOD__ . " customerId:{$customerId}");
        $customerInfo = $this->_objectManager->create("\Magento\Framework\DataObject");
        
        $customerInfo->setId($customerId);
        
        $customerInfo->setName($order->getCustomerName());
        $customerInfo->setEmail($order->getCustomerEmail());
        
        try {
            $address = $order->getBillingAddress();
            if ($address) {
                $customerInfo->setPhoneNumber($address->getTelephone());
                
                $streetFull = implode(" ", $address->getStreet()) . " " . $address->getCity() . ", " . $address->getRegion() . " " . $address->getPostcode() . " " . $address->getCountryId();
                
                $customerInfo->setAddress($streetFull);
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->_logger->critical($e->getMessage());
        }
        
        return $customerInfo;
    }
    
    public function findPayflexOrderForRefund($orderIncrementId, $info)
    {
        $this->_logger->info(__METHOD__);
        $payflexId = "";
        if (isset($info["orderId"])) {
            $payflexId = $info["orderId"];
            $this->_logger->info(__METHOD__ . "order:{$orderIncrementId} PayflexID: {$payflexId}");
            return $payflexId;
        }
        $this->_logger->info(__METHOD__ . " PayflexID not found");
        return $payflexId;
    }
    
    public function getWidgetHtml($totalAmount)
    {
        
        if (!$this->_configHelper->getEnabled()) return false;
        
        $storeId                      = $this->storeManager->getStore()->getId();
        $merchantConfigurationManager = $this->_objectManager->create("\Payflex\Gateway\Model\Configuration");
        $configurationModel           = $merchantConfigurationManager->load($storeId, "store_id");
        
        if ($configurationModel && !$configurationModel->getId())
        {
            $configurationModel = $this->refreshMerchantConfiguration($configurationModel, $storeId);
        }
        
        if ($configurationModel->getMin() && $configurationModel->getMax())
        {
            $merchantName = $this->_configHelper->getMerchantName($storeId) ?: 'your-merchant-name';
            $merchantName = urlencode(str_replace(' ', '-', $merchantName));
            
            if($this->_configHelper->getProductWidget($storeId))
            {
                if($merchantName AND $merchantName != 'your-merchant-name')
                {
                    return '<script async src="https://widgets.payflex.co.za/' . $merchantName . '/payflex-widget-2.0.1.js?type=calculator&min=' . $configurationModel->getMin() . '&max=' . $configurationModel->getMax() . '&amount=' . $totalAmount . '" type="application/javascript"></script>';
                }
                else
                {
                    return '<script async src="https://widgets.payflex.co.za/payflex-widget-2.0.1.js?type=calculator&min=' . $configurationModel->getMin() . '&max=' . $configurationModel->getMax() . '&amount=' . $totalAmount . '" type="application/javascript"></script>';
                }
            }
            else{
                return;
            }
        }
        return false;
    }
    
    public function refreshMerchantConfiguration($merchantConfigurationModel, $storeId)
    {
        $apiData = $this->_communicationHelper->getMerchantConfiguration($storeId);
        
        $merchantConfigurationModel->addData(
            array(
                "store_id" => $storeId,
                "min" => $apiData['minimumAmount'] ? $apiData['minimumAmount'] : '',
                "max" => $apiData['maximumAmount'] ? $apiData['maximumAmount'] : '',
            ));
            
            $merchantConfigurationModel->save();
            return $merchantConfigurationModel;
        }
        
    /**
    * This function is mainly run by the CRON job to check the status of orders that are pending payment.
    */
    public function checkOrderStatus($storeId)
    {
        $pMethod = 'payflex_gateway';

        $this->_logger->info(__METHOD__ .' for Store ID:'.$storeId);
        $orderFromDateTime = date("Y-m-d H:i:s", strtotime('-24 hours'));
        $orderToDateTime   = date("Y-m-d H:i:s", strtotime('-30 minutes'));
        $ocf = $this->_orderCollectionFactory->create();
        $ocf->addAttributeToSelect( 'entity_id');
        $ocf->addAttributeToSelect('increment_id');
        $ocf->addAttributeToSelect('store_id');
        $ocf->addAttributeToFilter( 'status', ['eq' => 'pending_payment'] );
        $ocf->addFieldToFilter('created_at', array('from' => $orderFromDateTime, 'to' => $orderToDateTime));
        $ocf->getSelect()
        ->join(
            ["sop" => "sales_order_payment"],
            'main_table.entity_id = sop.parent_id',
            array('method')
        )
        ->where('sop.method = ?',$pMethod );
        $ocf->setOrder(
            'increment_id',
            'desc'
        );
        $number_of_orders = $ocf->getSize();
        $this->_logger->info(__METHOD__ .' Number of orders:'.$number_of_orders);

        echo "Payflex: CRON: Checking ".$number_of_orders." order(s)".PHP_EOL;

        $orderIds = $ocf->getData(); 
        $this->_logger->info( 'Orders from storeID : '.$storeId .' for cron: ' . json_encode( $orderIds ) );
        foreach ( $orderIds as $orderId ) {
            $orderIncrementId = $orderId['increment_id'];  
            $storeId = $orderId['store_id'];  
            $requestTokenManager = $this->_objectManager->create("\Payflex\Gateway\Model\RequestToken");
            $requestToken = $requestTokenManager->load($orderIncrementId, "order_id");
            $this->_logger->info(__METHOD__ ."requestToken model data in cron : ". json_encode($requestToken));
            if($requestToken->getpayflexId()){
                $payflexOrderId = $requestToken->getpayflexId();
                $this->_logger->info(__METHOD__.'payflexOrderId : '.$payflexOrderId);
                $payflexApiResponse = $this->_communicationHelper->getTransactionStatus($payflexOrderId,$storeId);
                echo "Payflex: API Response for Order ID: ".$orderIncrementId." ".$payflexApiResponse["orderStatus"].PHP_EOL;
                if (!$payflexApiResponse) {
                    throw new \Magento\Framework\Exception\NotFoundException(__('Transaction status checking response format is incorrect.'));
                }
                if (isset($payflexApiResponse["orderStatus"]) && $payflexApiResponse["orderStatus"] == "Approved" && $payflexApiResponse["merchantReference"] == $orderIncrementId ){
                    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                    $order = $objectManager->get( '\Magento\Sales\Model\Order' )->loadByIncrementId( $orderIncrementId );
                    if($order->getId()) {
                        $this->_logger->info(__METHOD__.'Merchant REF : '.$order->getId());

                        $this->_logger->info(__METHOD__.'Order status set to Processing: '.$order->getIncrementId());
                        // Update order status message
                        $order->addStatusHistoryComment( __( 'PayFlex CRON: Payment was successful for order ID: '. $payflexOrderId ) )->setIsCustomerNotified( true )->save();
                        //   try {

                        $orderId      = $order->getId();
                        $quoteId      = $order->getQuoteId();
                        $quote        = $this->loadQuote($quoteId);
                        $payment      = $quote->getPayment();
                        
                        
                        // Get the order payment object
                        $this->_logger->info(__METHOD__.'Generating Transaction for Order ID: '.$orderIncrementId);
                        $this->createTransaction( $order, $payflexApiResponse );
                        
                        if ($order->canInvoice() && !$order->hasInvoices())
                        {
                            $this->_logger->info(__METHOD__.'Generating Invoice for Order ID: '.$orderIncrementId);
                            $this->generateInvoice( $order );
                        }
                        
                    }
                }
                elseif (isset($payflexApiResponse["orderStatus"]) && in_array($payflexApiResponse['orderStatus'],["Declined","Abandoned"]))
                {
                    echo "Payflex: Order ID: ".$orderIncrementId." ".$payflexApiResponse['orderStatus'].PHP_EOL;
                    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                    $order = $objectManager->get( '\Magento\Sales\Model\Order' )->loadByIncrementId( $orderIncrementId );
                    
                    $order_processing_status = $this->_configHelper->getPayflexNewOrderStatus($this->storeManager->getStore()->getId());
                    
                    if($order->getStatus() != $order_processing_status)
                    {
                        echo "Payflex: Updating Order ID: ".$orderIncrementId." to ".Order::STATE_CANCELED.PHP_EOL;
                        if($order->canCancel()) {
                            $order->setEmailSent(0);
                            $order->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
                            $this->orderManagement->cancel($order->getId());

                            $order->addStatusHistoryComment('PayFlex CRON: Transaction '.strtolower($payflexApiResponse['orderStatus']).' from Payflex window, Order ID:'. $payflexOrderId)->save();
                            $order->cancel();
                            $order->save();
                        }
                    }
                    
                    $this->_logger->info(__METHOD__ . " The PayFlex order status for orderIncrementId " . $orderIncrementId . " is Declined or Abandoned.");
                    
                } elseif (isset($payflexApiResponse["orderStatus"]) && $payflexApiResponse['orderStatus'] == "Created"){
                    $this->_logger->info(__METHOD__ . " The PayFlex order status for Quote ID: " .  $orderIncrementId . " is currently set to Created. This order will be checked again on the next cron run.");
                    
                }
                
            }else{
                $this->_logger->info(__METHOD__ ."Order does not exist in RequestToken Model" );
                throw new \Magento\Framework\Exception\LocalizedException(__('The order no longer exists.'));
            }
        }
        
        $this->_logger->info(__METHOD__ . " PayFlex order status cron has executed for store ID " . $storeId . ".");
    }
        
    public function generateInvoice( $order )
    {
        $storeId = $this->storeManager->getStore()->getId();
        $order_successful_email = $this->_configHelper->getOrderEmail($storeId);
        
        $state  = $this->_configHelper->getPayflexNewOrderState($this->storeManager->getStore()->getId());
        $status = $this->_configHelper->getPayflexNewOrderStatus($this->storeManager->getStore()->getId());
        
        
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
    public function createTransaction( $order = null, $paymentData = array() )
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
            $trans       = $this->transactionBuilder;
            
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
    
    public function loadQuote($quoteId)
    {
        $this->_logger->info(__METHOD__ . " QuoteId:{$quoteId}");
        
        $quote = $this->_quoteFactory->create()->loadByIdWithoutStore($quoteId);
        if (!$quote->getId()) {
            $error = "Failed to load quote : {$quoteId}";
            $this->_logger->critical($error);
            return null;
        }
        return $quote;
    }
}