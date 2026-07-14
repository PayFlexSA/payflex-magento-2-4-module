<?php
namespace Payflex\Gateway\Helper\Payflex;

use Magento\Catalog\Model\ProductFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject\Factory as DataObjectFactory;
use Magento\Framework\Exception\State\InvalidTransitionException;
use Magento\Framework\Module\ModuleListInterface;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Helper\Support;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\RequestTokenFactory;

/**
 * Builds the Payflex "create session" request from a quote, calls Payflex to
 * obtain the hosted-checkout redirect URL, and persists the resulting request
 * token so the return controllers can verify it later.
 */
class UrlCreator
{
    /** @var PayflexLogger */
    private $_logger;

    /** @var Configuration */
    private $_configuration;

    /** @var Communication */
    private $_communication;

    /** @var Support */
    private $support;

    /** @var RequestTokenFactory */
    private $requestTokenFactory;

    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var ModuleListInterface */
    private $moduleList;

    /** @var DataObjectFactory */
    private $dataObjectFactory;

    /** @var ProductFactory */
    private $productFactory;

    public function __construct(
        PayflexLogger $logger,
        Configuration $configuration,
        Communication $communication,
        Support $support,
        RequestTokenFactory $requestTokenFactory,
        CheckoutSession $checkoutSession,
        ModuleListInterface $moduleList,
        DataObjectFactory $dataObjectFactory,
        ProductFactory $productFactory
    ) {
        $this->_logger = $logger;
        $this->_configuration = $configuration;
        $this->_communication = $communication;
        $this->support = $support;
        $this->requestTokenFactory = $requestTokenFactory;
        $this->checkoutSession = $checkoutSession;
        $this->moduleList = $moduleList;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->productFactory = $productFactory;
    }

    public function CreateUrl(\Magento\Quote\Model\Quote $quote)
    {
        $this->_logger->info(__METHOD__);
        $requestData = $this->_buildPayflexRequestData($quote);

        try {
            $response = $this->_communication->getPayflexPage($requestData);
        } catch (\Exception $ex) {
            $this->_logger->error(__METHOD__ . $ex->getMessage());
            throw new InvalidTransitionException(__('Error in communication with Payflex:' . $ex->getMessage()));
        }

        if (!$response || empty($response['redirectUrl'])) {
            $error = "Invalid response from Payflex: " . json_encode($response);
            $this->_logger->critical(__METHOD__ . " " . $error);
            throw new InvalidTransitionException(__($error));
        }

        $requestTokenModel = $this->requestTokenFactory->create();
        $requestToken = $requestTokenModel->load($quote->getReservedOrderId(), "order_id");
        if ($requestToken->getId()) {
            $requestToken->setpayflexId($response['orderId']);
            $requestToken->setUrl($response['redirectUrl']);
            $requestToken->setToken($response['token']);
            $requestToken->setExpire($response['expiryDateTime']);
            $requestToken->save();
        } else {
            $requestTokenModel->setData([
                "token"      => $response['token'],
                "url"        => $response['redirectUrl'],
                "expire"     => $response['expiryDateTime'],
                "order_id"   => $quote->getReservedOrderId(),
                "payflex_id" => $response['orderId'],
            ]);
            $requestTokenModel->save();
        }

        $this->checkoutSession->setpayflexQuoteId($quote->getId());

        //Log the Payflex ID that is received from the API.
        $this->_logger->info(__METHOD__ . " Payflex Order ID: " . $response['orderId']);

        //Add the Payflex ID to the quote.
        $info = [];
        $quotePayment = $quote->getPayment();
        if (!empty($quotePayment->getAdditionalInformation())) {
            $source = $this->dataObjectFactory->create($quotePayment->getAdditionalInformation());
            $info["cartId"]      = $source->getData("cartId");
            $info["guestEmail"]  = $source->getData("guestEmail");
            $info["orderId"]     = $response['orderId'];
            $info["orderStatus"] = "Not Set";

            $info["merchantReference"] = $quote->getReservedOrderId();
            $info["amount"]            = (string)$quote->getBaseGrandTotal();
            $info["currency"]          = (string)$quote->getBaseCurrencyCode();

            // Record the plugin version in use at placement time, for support/debugging.
            $moduleInfo = $this->moduleList->getOne("Payflex_Gateway");
            if (isset($moduleInfo["setup_version"])) {
                $info["payflex_module_version"] = $moduleInfo["setup_version"];
            }
        }
        $quotePayment->setAdditionalInformation($info);
        $quotePayment->save();

        return (string)$response['redirectUrl'];
    }

    private function _buildPayflexRequestData(\Magento\Quote\Model\Quote $quote)
    {
        $orderIncrementId = $quote->getReservedOrderId();
        $this->_logger->info(__METHOD__ . " orderIncrementId:{$orderIncrementId}");

        $customerInfo = $this->_loadCustomerInfo($quote);

        $platform_info_string = 'Magento ' . $this->support->getMagentoVersion();

        //format order
        $param = [];
        $param['amount'] = $quote->getBaseGrandTotal();

        $param['consumer']['phoneNumber'] = $customerInfo->getPhoneNumber();
        $param['consumer']['givenNames']  = $customerInfo->getFirstname();
        $param['consumer']['surname']     = $customerInfo->getSurname();
        $param['consumer']['email']       = $quote->getBillingAddress()->getEmail();

        $param['billing']['addressLine1'] = $customerInfo->getBillingStreet1();
        $param['billing']['addressLine2'] = $customerInfo->getBillingStreet2();
        $param['billing']['suburb']       = '';
        $param['billing']['city']         = $quote->getBillingAddress()->getCity();
        $param['billing']['postcode']     = $quote->getBillingAddress()->getPostcode();
        $param['billing']['state']        = $quote->getBillingAddress()->getRegion() ? $quote->getBillingAddress()->getRegion() : '';
        $param['billing']['country']      = $quote->getBillingAddress()->getCountry();

        $param['shipping']['addressLine1'] = $customerInfo->getShippingStreet1();
        $param['shipping']['addressLine2'] = $customerInfo->getShippingStreet2();
        $param['shipping']['suburb']       = '';
        $param['shipping']['city']         = $quote->getShippingAddress()->getCity();
        $param['shipping']['postcode']     = $quote->getShippingAddress()->getPostcode();
        $param['shipping']['state']        = $quote->getShippingAddress()->getRegion();
        $param['shipping']['country']      = $quote->getShippingAddress()->getCountry();

        $param['merchantSystemInformation']['plugin_version']     = $this->support->getModuleVersion();
        $param['merchantSystemInformation']['php_version']        = $this->support->getPHPVersion();
        $param['merchantSystemInformation']['ecommerce_platform'] = $platform_info_string;

        $param['description'] = '';

        $productManager = $this->productFactory->create();
        //format all items in cart
        foreach ($quote->getAllVisibleItems() as $item) {
            /** @var \Magento\Catalog\Model\Product $product */
            $product = $productManager->load($item->getProductId());
            $param['items'][] = [
                'description' => $product->getDescription(),
                'name'        => $item->getName(),
                'sku'         => $item->getSku(),
                'quantity'    => $item->getQty(),
                'price'       => $item->getBaseRowTotalInclTax(),
            ];
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
        $customerInfo = $this->dataObjectFactory->create();

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
            $this->_logger->critical($e->getMessage());
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

        return $customerFirstname;
    }
}
