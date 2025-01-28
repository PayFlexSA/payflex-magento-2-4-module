<?php
namespace Payflex\Gateway\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

class PaymentUtil extends AbstractHelper
{

    /**
     *
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;

    /**
     * Asset service
     *
     * @var \Magento\Framework\View\Asset\Repository
     */
    private $_assetRepo;

    /**
     * @var \Payflex\Gateway\Helper\Communication
     */
    protected $_communicationHelper;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    private $invoiceSender;


    /**
     * @var \Payflex\Gateway\Helper\Configuration
     */
    protected $_configHelper;

    protected $_orderCollectionFactory;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;
    private $OrderSender;
    private $_invoiceService;
    /**
     * @var Magento\Sales\Model\Order\Payment\Transaction\Builder $_transactionBuilder
     */

    protected $_transactionBuilder;
    /**
     * @var \Payflex\Gateway\Logger\PayflexLogger
     */
    protected $_logger;
    
    public function __construct(
        Context $context,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory
    )
    {
        $this->_orderCollectionFactory = $orderCollectionFactory;
        parent::__construct($context);
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $this->_objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_assetRepo = $this->_objectManager->get("\Magento\Framework\View\Asset\Repository");
        $this->_communicationHelper = $this->_objectManager->get("\Payflex\Gateway\Helper\Communication");
        $this->_configHelper = $this->_objectManager->get("\Payflex\Gateway\Helper\Configuration");
        $this->_storeManager = $storeManager;
        $this->orderRepository = $this->_objectManager->get("\Magento\Sales\Api\OrderRepositoryInterface");
        $this->OrderSender  =  $this->_objectManager->get("\Magento\Sales\Model\Order\Email\Sender\OrderSender");
        $this->_invoiceService =  $this->_objectManager->get("\Magento\Sales\Model\Service\InvoiceService");
        $this->_transactionBuilder = $this->_objectManager->get("\Magento\Sales\Model\Order\Payment\Transaction\Builder");
        $this->_logger->info(__METHOD__);
    }

    public function buildRedirectUrl()
    {
        $this->_logger->info(__METHOD__);
        $urlManager = $this->_objectManager->get('\Magento\Framework\Url');
        $url = $urlManager->getUrl('payflex/order/redirect', ['_secure' => true]);

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
        $storeId = $this->_storeManager->getStore()->getId();
        $merchantConfigurationManager = $this->_objectManager->create("\Payflex\Gateway\Model\Configuration");
        $configurationModel = $merchantConfigurationManager->load($storeId, "store_id");
        if ($configurationModel && !$configurationModel->getId()) {
            $configurationModel = $this->refreshMerchantConfiguration($configurationModel, $storeId);
        }

        if ($configurationModel->getMin() && $configurationModel->getMax()) {
            $merchantName = $this->_configHelper->getMerchantName($storeId) ?: 'your-merchant-name';
            $merchantName = urlencode(str_replace(' ', '-', $merchantName));

            if($this->_configHelper->getProductWidget($storeId)){
                if($merchantName AND $merchantName != 'your-merchant-name')
                {
                  return '<script async src="https://widgets.payflex.co.za/' . $merchantName . '/payflex-widget-2.0.0.js?type=calculator&min=' . $configurationModel->getMin() . '&max=' . $configurationModel->getMax() . '&amount=' . $totalAmount . '" type="application/javascript"></script>';
                }
                else
                {
                  return '<script async src="https://widgets.payflex.co.za/payflex-widget-2.0.0.js?type=calculator&min=' . $configurationModel->getMin() . '&max=' . $configurationModel->getMax() . '&amount=' . $totalAmount . '" type="application/javascript"></script>';
                }
            }else{
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
    public function createTransaction( $order = null, $paymentData = array() )
    {
      $this->_logger->info(__METHOD__);
        try {
            if ( $paymentData['orderStatus'] != 'Approved' && $paymentData['merchantReference'] != $order->getIncrementId()) {
                $this->_logger->info(__METHOD__.': Order Mismatched in cron');  
                return false;
            }
            // Get payment object from order object
            $payment = $order->getPayment();
            $this->_logger->info(__METHOD__.' Get payment');
            $payment->setLastTransId( $paymentData['orderId'] )
                ->setTransactionId( $paymentData['orderId'] ) ;
               // ->setAdditionalInformation($paymentData)  ;
            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $message = __( 'Cron : The authorized amount is %1.', $formatedPrice );
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
            $order->save();

            return $transaction->save()->getTransactionId();
        } catch ( \Exception $e ) {

            $this->_logger->error( $e->getMessage() );
        }
    }
    public function checkOrderStatus($storeId)
    {  
      $pMethod = 'payflex_gateway';
      echo "Payflex CRON is running".PHP_EOL;
      $this->_logger->info(__METHOD__ .' for Store ID:'.$storeId);
      $orderFromDateTime = date("Y-m-d H:i:s", strtotime('-24 hours'));
      $orderToDateTime = date("Y-m-d H:i:s", strtotime('-30 minutes'));
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
              if (!$payflexApiResponse) {
                throw new \Magento\Framework\Exception\NotFoundException(__('Transaction status checking response format is incorrect.'));
              }
              if (isset($payflexApiResponse["orderStatus"]) && $payflexApiResponse["orderStatus"] == "Approved" && $payflexApiResponse["merchantReference"] == $orderIncrementId ){
                  $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                  $order = $objectManager->get( '\Magento\Sales\Model\Order' )->loadByIncrementId( $orderIncrementId );
                  if($order->getId()) {
                      $this->_logger->info(__METHOD__.'Merchant REF : '.$order->getId());
                      $status = \Magento\Sales\Model\Order::STATE_PROCESSING;
                      $order->setStatus( $status );
                      $order->setState( $status );
                      $order->save();
                      $this->_logger->info(__METHOD__.'Order status set to Processing: '.$order->getId());
                      try {
                        $this->generateInvoice( $order );
                        $this->createTransaction( $order, $payflexApiResponse );
                      } catch ( \Exception $ex ) {
                          $this->_logger->error( $ex->getMessage() );
                      }
                  }
                }
                elseif (isset($payflexApiResponse["orderStatus"]) && in_array($payflexApiResponse['orderStatus'],["Declined","Abandoned"])) {
                  $this->_logger->info(__METHOD__ . " The PayFlex order status for orderIncrementId " . $orderIncrementId . " is Declined or Abandoned.");

                } elseif (isset($payflexApiResponse["orderStatus"]) && $payflexApiResponse['orderStatus'] == "Created"){
                  $this->_logger->info(__METHOD__ . " The PayFlex order status for Quote ID: " .  $orderIncrementId . " is currently set to Created. This order will be checked again on the next cron run.");

                }

          }else{
            $this->_logger->info(__METHOD__ ."Order is not exist in RequestToken Model" );
            throw new \Magento\Framework\Exception\LocalizedException(__('The order no longer exists.'));
          }
      }

      $payflexDetailedEmailDebugging = 0; // 0 = off, 1 = on. If on, make sure to define your to addresss
      if ($payflexDetailedEmailDebugging == 1){

        //Send email with order variables.
        date_default_timezone_set("Africa/Johannesburg");
        $datetimeofmail = date("Y-m-d H:i:s");
        $to = "PayFlex Tester <youraddress@yourdomain.tld>";
        $subject = $datetimeofmail . " Order loop " ;

        //Message body start
        $message = "Quote ID: ";
        if (isset($quoteReservedOrderId)){
          $message .= "\nReserved Order ID: " . $quoteReservedOrderId . ".";
        } else {
          $message .= "\nNo Reserved Order ID to display.";
        }
        //$message .= ". \nMagento Status: " . $orderStatus;
        if (isset($quoteDateTimeCreated)){
          $message .= "\nQuote DateTime Created: " . $quoteDateTimeCreated . ".";
        } else {
          $message .= "\nNo Quote DateTime to display.";
        }
        if (isset($quotePaymentMethodTitle)){
          $message .= "\nPayment Method Title: " . $quotePaymentMethodTitle . ".";
        } else {
          $message .= "\nNo Payment Method Title to display.";
        }
        if (isset($quotePaymentMethodCode)){
          $message .= "\nPayment Method Code: " . $quotePaymentMethodCode . ".";
        } else {
          $message .= "\nNo Payment Method Code to display.";
        }
        if (isset($payflexOrderId)){
          $message .= "\nPayFlex ID: " . $payflexOrderId . ".";
        } else {
          $message .= "\nNo PayFlex ID to display.";
        }
        if (isset($payflexOrderStatus)){
          $message .= "\nCurrent PayFlex Payment Status: " . $payflexOrderStatus . ".";
        } else {
          $message .= "\nNo Current PayFlex Payment Status to display.";
        }
        if (isset($payflexApiResponseStatus)){
          $message .= "\nPayFlex API Payment Status: " . $payflexApiResponseStatus . ".";
        } else {
          $message .= "\nNo PayFlex API Payment Status to display.";
        }
        $message .= "\n=========\n";
        if (isset($quotePaymentAdditionalInfo)){
          $message .= "\nDump of additional info:\n" . var_export($quotePaymentAdditionalInfo, true);
        } else {
          $message .= "\nNo additional info to display.";
        }
        $message .= "\n=========\n";
        if (isset($payflexApiResponse)){
          $message .= "\nResponse from PayFlex API: \n" . var_export($payflexApiResponse, true);
        } else {
          $message .= "\nNo response from the PayFlex API to display.";
        }
        $message .= "\n=========\n";
        //Message body end

        $headers = array(
          "From" => "PayFlex Debugger <payflex@yourtestdomain.tld>",
          "Reply-To" => "PayFlex Debugger <payflex@yourtestdomain.tld>",
          "X-Mailer" => "PHP/" . phpversion()
        );
        mail($to, $subject, $message, $headers);
        $this->_logger->info(__METHOD__ . " Order loop  was processed and email was sent.");
        sleep(1);
      }
    //return $collection;
      $this->_logger->info(__METHOD__ . " PayFlex order status cron has executed for store ID " . $storeId . ".");
    }

    public function generateInvoice( $order )
    {
        $this->_logger->info(__METHOD__);
        $storeId = $this->_storeManager->getStore()->getId();
        $order_successful_email = $this->_configHelper->getOrderEmail($storeId);

        if ( $order_successful_email != '0' ) {
            $this->OrderSender->send( $order );
            $this->_logger->info(__METHOD__.'Order Success Email Sent');
            $order->addStatusHistoryComment( __( 'Notified customer about order #%1.', $order->getId() ) )->setIsCustomerNotified( true )->save();
        }
        // Capture invoice when payment is successfull
        $invoice = $this->_invoiceService->prepareInvoice( $order );
        $invoice->setRequestedCaptureCase( \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE );
        $invoice->register();
        $this->_logger->info(__METHOD__.'invoice registred');
        // Save the invoice to the order
        $transaction = $this->_objectManager->create( 'Magento\Framework\DB\Transaction' )
            ->addObject( $invoice )
            ->addObject( $invoice->getOrder() );

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

}
