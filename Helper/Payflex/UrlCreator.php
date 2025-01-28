<?php
namespace Payflex\Gateway\Helper\Payflex;

use \Magento\Framework\Exception\State\InvalidTransitionException;

class UrlCreator
{

    /**
     *
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;

    /**
     *
     * @var \Payflex\Gateway\Logger\PayflexLogger
     */
    private $_logger;

    /**
     *
     * @var \Payflex\Gateway\Helper\Configuration
     */
    protected $_configuration;

    /**
     *
     * @var \Payflex\Gateway\Helper\Communication
     */
    protected $_communication;

    public function __construct()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_objectManager = $objectManager;
        $this->_communication = $objectManager->get("\Payflex\Gateway\Helper\Communication");
        $this->_logger = $objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_configuration = $objectManager->get("\Payflex\Gateway\Helper\Configuration");

        $this->_logger->info('UrlCreator:'.__METHOD__);
    }

    public function CreateUrl(\Magento\Quote\Model\Quote $quote)
    {
        $this->_logger->info(__METHOD__);
        $requestData = $this->_buildPayflexRequestData($quote);

        try {
            $response = $this->_communication->getPayflexPage($requestData);
        }catch (\Exception $ex) {
            $this->_logger->error(__METHOD__.$ex->getMessage());
            throw new InvalidTransitionException(__('Error in communication with PayFlex:'.$ex->getMessage()));
            return false;
        }

        if (!$response || empty($response['redirectUrl'])) {
            $error = "Invalid response from PayFlex: " . json_encode($response);
            $this->_logger->critical(__METHOD__ . " " . $error);
            throw new InvalidTransitionException(__($error));
            return false;
        }

       $requestTokenModel = $this->_objectManager->create("\Payflex\Gateway\Model\RequestToken");
        $requestToken = $requestTokenModel->load($quote->getReservedOrderId(), "order_id");
        if($requestToken->getId()){
            $requestToken->setpayflexId($response['orderId']);
            $requestToken->setUrl($response['redirectUrl']);
            $requestToken->setToken($response['token']);
            $requestToken->setExpire($response['expiryDateTime']);
            $requestToken->save();
        }else{
            $requestTokenModel->setData(
                array(
                    "token" => $response['token'],
                    "url" => $response['redirectUrl'],
                    "expire" => $response['expiryDateTime'],
                    "order_id" => $quote->getReservedOrderId(),
                    "payflex_id" => $response['orderId'],
                ));
                $requestTokenModel->save();
        }
        $session = $this->_objectManager->get('\Magento\Checkout\Model\Session');
        $session->setpayflexQuoteId($quote->getId());

        //Log the PayFlex ID that is received from the API.
        $this->_logger->info(__METHOD__ . " PayFlex Order ID: " . $response['orderId']);

        //Add the PayFlex ID to the quote.
        $info = []; 
        $quotePayment = $quote->getPayment();
        if (!empty($quotePayment->getAdditionalInformation())){
          $quotePaymentAdditionalInfo = $quotePayment->getAdditionalInformation();
          $source = $this->_objectManager->create("\Magento\Framework\DataObject");
          $source->setData($quotePaymentAdditionalInfo);
          $info["cartId"] = $source->getData("cartId");
          $info["guestEmail"] = $source->getData("guestEmail");
          $info["orderId"] = $response['orderId'];
          $info["orderStatus"] = "Not Set";
        }
        $quotePayment->setAdditionalInformation($info);
        $quotePayment->save();
        //Continue with the URL creation function.
        return (string)$response['redirectUrl'];
    }

    private function _buildPayflexRequestData(\Magento\Quote\Model\Quote $quote)
    {
        $orderIncrementId = $quote->getReservedOrderId();
        $this->_logger->info(__METHOD__ . " orderIncrementId:{$orderIncrementId}");

        $customerInfo = $this->_loadCustomerInfo($quote);
        //format order
        $param = array();
        $param['amount'] = $quote->getBaseGrandTotal();

        $param['consumer']['phoneNumber'] = $customerInfo->getPhoneNumber();
        $param['consumer']['givenNames'] = $customerInfo->getFirstname();
        $param['consumer']['surname'] = $customerInfo->getSurname();
        $param['consumer']['email'] = $quote->getBillingAddress()->getEmail();

        $param['billing']['addressLine1'] = $customerInfo->getBillingStreet1();
        $param['billing']['addressLine2'] = $customerInfo->getBillingStreet2();
        $param['billing']['suburb'] = '';
        $param['billing']['city'] = $quote->getBillingAddress()->getCity();
        $param['billing']['postcode'] = $quote->getBillingAddress()->getPostcode();
        $param['billing']['state'] = $quote->getBillingAddress()->getRegion() ? $quote->getBillingAddress()->getRegion() : '';
        $param['billing']['country'] = $quote->getBillingAddress()->getCountry();

        $param['shipping']['addressLine1'] = $customerInfo->getShippingStreet1();
        $param['shipping']['addressLine2'] = $customerInfo->getShippingStreet2();
        $param['shipping']['suburb'] = '';
        $param['shipping']['city'] = $quote->getShippingAddress()->getCity();
        $param['shipping']['postcode'] = $quote->getShippingAddress()->getPostcode();
        $param['shipping']['state'] = $quote->getShippingAddress()->getRegion();
        $param['shipping']['country'] = $quote->getShippingAddress()->getCountry();

        $param['description'] = '';

        $productManager = $this->_objectManager->create("\Magento\Catalog\Model\Product");
        //format all items in cart
        foreach ( $quote->getAllVisibleItems() as $item){
            /**
             * @var \Magento\Catalog\Model\Product $product
             */
            $product = $productManager->load($item->getProductId());
            $param['items'][] = array(
                'description' => $product->getDescription(),
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'quantity' => $item->getQty(),
                'price' => $item->getBaseRowTotalInclTax(),
            );
        }

        $param['merchantReference'] = $orderIncrementId;
        $param['taxAmount'] = $quote->getShippingAddress()->getBaseTaxAmount();
        $param['shippingAmount'] = $quote->getShippingAddress()->getBaseShippingAmount();

        $this->_logger->info(__METHOD__ . " param:" . var_export($param, true));
        return $param;
    }

    private function _loadCustomerInfo(\Magento\Quote\Model\Quote $quote)
    {
        $customerId = $quote->getCustomerId();
        $this->_logger->info(__METHOD__ . " customerId:{$customerId}");
        $customerInfo = $this->_objectManager->create("\Magento\Framework\DataObject");

        $customerInfo->setId($customerId);

        $customerInfo->setFirstname($this->_getCustomerFirstname($quote));
        $customerInfo->setSurname($this->_getCustomerSurname($quote));
        $customerInfo->setEmail($quote->getCustomerEmail());

        try {
            $billingAddress = $quote->getBillingAddress();
            if ($billingAddress) {
                $customerInfo->setPhoneNumber($billingAddress->getTelephone());

                $billingStreetData = $billingAddress->getStreet();
                $streetFull = implode(" ", $billingStreetData) . " " . $billingAddress->getCity() . ", " .
                    $billingAddress->getRegion() . " " . $billingAddress->getPostcode() . " " . $billingAddress->getCountryId();
                if (isset($billingStreetData[0])) {
                    $customerInfo->setBillingStreet1($billingStreetData[0]);
                }
                if (isset($billingStreetData[1])) {
                    $customerInfo->setBillingStreet2($billingStreetData[1]);
                }
                $customerInfo->setFullAddress($streetFull);
            }
            if ($shippingAddress = $quote->getShippingAddress()) {
                $shippingStreetData = $shippingAddress->getStreet();
                if (isset($shippingStreetData[0])) {
                    $customerInfo->setShippingStreet1($shippingStreetData[0]);
                }
                if (isset($shippingStreetData[1])) {
                    $customerInfo->setShippingStreet2($shippingStreetData[1]);
                }
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->_logger->critical($e->_toString());
        }

        return $customerInfo;
    }

    /**
     * Retrieve customer surname name
     *
     * @return string
     */
    private function _getCustomerSurname(\Magento\Quote\Model\Quote $quote)
    {
        if ($quote->getCustomerLastname()) {
            $customerSurname = $quote->getCustomerLastname();
        } else {
            $customerSurname = $quote->getBillingAddress()->getLastname();
        }

        $this->_logger->info(__METHOD__ . " customerSurname:{$customerSurname}");
        return $customerSurname;
    }

    /**
     * Retrieve customer first name
     *
     * @return string
     */
    private function _getCustomerFirstname(\Magento\Quote\Model\Quote $quote)
    {
        if ($quote->getCustomerFirstname()) {
            $customerFirstname = $quote->getCustomerFirstname();
        } else {
            $customerFirstname = $quote->getBillingAddress()->getFirstname();
        }

        $this->_logger->info(__METHOD__ . " customerFirstname:{$customerFirstname}");
        return $customerFirstname;
    }
}
