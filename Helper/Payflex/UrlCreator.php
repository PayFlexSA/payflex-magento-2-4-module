<?php
namespace Payflex\Gateway\Helper\Payflex;

use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\DataObject\Factory as DataObjectFactory;
use Magento\Framework\Exception\State\InvalidTransitionException;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Sales\Model\Order;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Helper\Support;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\RequestTokenFactory;
use Payflex\Gateway\Model\ResourceModel\RequestToken as RequestTokenResource;

/**
 * Builds the Payflex "create session" request from an order, calls Payflex to
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

    /** @var ModuleListInterface */
    private $moduleList;

    /** @var DataObjectFactory */
    private $dataObjectFactory;

    /** @var ProductFactory */
    private $productFactory;

    /** @var RequestTokenResource */
    private $requestTokenResource;

    public function __construct(
        PayflexLogger $logger,
        Configuration $configuration,
        Communication $communication,
        Support $support,
        RequestTokenFactory $requestTokenFactory,
        ModuleListInterface $moduleList,
        DataObjectFactory $dataObjectFactory,
        ProductFactory $productFactory,
        RequestTokenResource $requestTokenResource
    ) {
        $this->_logger = $logger;
        $this->_configuration = $configuration;
        $this->_communication = $communication;
        $this->support = $support;
        $this->requestTokenFactory = $requestTokenFactory;
        $this->moduleList = $moduleList;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->productFactory = $productFactory;
        $this->requestTokenResource = $requestTokenResource;
    }

    /**
     * Create a Payflex session for the order and return the Payflex redirect URL.
     */
    public function createUrl(Order $order)
    {
        $this->_logger->info(__METHOD__);
        $requestData = $this->_buildPayflexRequestData($order);

        try {
            $response = $this->_communication->getPayflexPage($requestData, $order->getStoreId());
        } catch (\Exception $ex) {
            $this->_logger->error(__METHOD__ . $ex->getMessage());
            throw new InvalidTransitionException(__('Error in communication with Payflex:' . $ex->getMessage()));
        }

        if (!$response || empty($response['redirectUrl'])) {
            $error = "Invalid response from Payflex: " . json_encode($response);
            $this->_logger->critical(__METHOD__ . " " . $error);
            throw new InvalidTransitionException(__($error));
        }

        $orderIncrementId = $order->getIncrementId();
        $existingIds = $this->requestTokenResource->getPayflexIds($orderIncrementId);
        if (!empty($existingIds)) {
            $this->_logger->warning(__METHOD__ . " order {$orderIncrementId} already has Payflex order(s) "
                . implode(', ', $existingIds) . "; adding {$response['orderId']}");
        }

        $requestTokenModel = $this->requestTokenFactory->create();
        $requestTokenModel->setData([
            "token"      => $response['token'],
            "url"        => $response['redirectUrl'],
            "expire"     => $response['expiryDateTime'],
            "order_id"   => $orderIncrementId,
            "payflex_id" => $response['orderId'],
        ]);
        $requestTokenModel->save();

        //Log the Payflex ID that is received from the API.
        $this->_logger->info(__METHOD__ . " Payflex Order ID: " . $response['orderId']);

        //Add the Payflex ID to the order payment.
        $payment = $order->getPayment();
        $info = $payment->getAdditionalInformation();
        $info["orderId"]     = $response['orderId'];
        $info["orderStatus"] = "Not Set";

        $info["merchantReference"] = $orderIncrementId;
        $info["amount"]            = (string)$order->getBaseGrandTotal();
        $info["currency"]          = (string)$order->getBaseCurrencyCode();

        // Record the plugin version in use at placement time, for support/debugging.
        $moduleInfo = $this->moduleList->getOne("Payflex_Gateway");
        if (isset($moduleInfo["setup_version"])) {
            $info["payflex_module_version"] = $moduleInfo["setup_version"];
        }
        $payment->setAdditionalInformation($info);
        $payment->save();

        return (string)$response['redirectUrl'];
    }

    private function _buildPayflexRequestData(Order $order)
    {
        $orderIncrementId = $order->getIncrementId();
        $this->_logger->info(__METHOD__ . " orderIncrementId:{$orderIncrementId}");

        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $this->_getShippingAddress($order);
        $customerInfo = $this->_loadCustomerInfo($order);

        $platform_info_string = 'Magento ' . $this->support->getMagentoVersion();

        //format order
        $param = [];
        $param['amount'] = $order->getBaseGrandTotal();

        $param['consumer']['phoneNumber'] = $customerInfo->getPhoneNumber();
        $param['consumer']['givenNames']  = $customerInfo->getFirstname();
        $param['consumer']['surname']     = $customerInfo->getSurname();
        $param['consumer']['email']       = $order->getCustomerEmail();

        $param['billing']['addressLine1'] = $customerInfo->getBillingStreet1();
        $param['billing']['addressLine2'] = $customerInfo->getBillingStreet2();
        $param['billing']['suburb']       = '';
        $param['billing']['city']         = $billingAddress->getCity();
        $param['billing']['postcode']     = $billingAddress->getPostcode();
        $param['billing']['state']        = $billingAddress->getRegion() ? $billingAddress->getRegion() : '';
        $param['billing']['country']      = $billingAddress->getCountryId();

        $param['shipping']['addressLine1'] = $customerInfo->getShippingStreet1();
        $param['shipping']['addressLine2'] = $customerInfo->getShippingStreet2();
        $param['shipping']['suburb']       = '';
        $param['shipping']['city']         = $shippingAddress->getCity();
        $param['shipping']['postcode']     = $shippingAddress->getPostcode();
        $param['shipping']['state']        = $shippingAddress->getRegion();
        $param['shipping']['country']      = $shippingAddress->getCountryId();

        $param['merchantSystemInformation']['plugin_version']     = $this->support->getModuleVersion();
        $param['merchantSystemInformation']['php_version']        = $this->support->getPHPVersion();
        $param['merchantSystemInformation']['ecommerce_platform'] = $platform_info_string;

        $param['description'] = '';

        $productManager = $this->productFactory->create();
        //format all items in the order
        foreach ($order->getAllVisibleItems() as $item) {
            /** @var \Magento\Catalog\Model\Product $product */
            $product = $productManager->load($item->getProductId());
            $param['items'][] = [
                'description' => $product->getDescription(),
                'name'        => $item->getName(),
                'sku'         => $item->getSku(),
                'quantity'    => $item->getQtyOrdered(),
                'price'       => $item->getBaseRowTotalInclTax(),
            ];
        }

        $param['merchantReference'] = $orderIncrementId;
        $param['taxAmount'] = $order->getBaseTaxAmount();
        $param['shippingAmount'] = $order->getBaseShippingAmount();

        $this->_logger->info(__METHOD__ . " param:" . var_export($param, true));
        return $param;
    }

    private function _loadCustomerInfo(Order $order)
    {
        $customerId = $order->getCustomerId();
        $this->_logger->info(__METHOD__ . " customerId:{$customerId}");
        $customerInfo = $this->dataObjectFactory->create();

        $customerInfo->setId($customerId);
        $customerInfo->setFirstname($this->_getCustomerFirstname($order));
        $customerInfo->setSurname($this->_getCustomerSurname($order));
        $customerInfo->setEmail($order->getCustomerEmail());

        try {
            $billingAddress = $order->getBillingAddress();
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
            if ($shippingAddress = $this->_getShippingAddress($order)) {
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
     * The order shipping address, or the billing address for virtual orders.
     *
     * @return \Magento\Sales\Model\Order\Address
     */
    private function _getShippingAddress(Order $order)
    {
        $shippingAddress = $order->getShippingAddress();
        if (!$shippingAddress) {
            return $order->getBillingAddress();
        }

        return $shippingAddress;
    }

    /**
     * Retrieve customer surname name
     *
     * @return string
     */
    private function _getCustomerSurname(Order $order)
    {
        if ($order->getCustomerLastname()) {
            $customerSurname = $order->getCustomerLastname();
        } else {
            $customerSurname = $order->getBillingAddress()->getLastname();
        }

        return $customerSurname;
    }

    /**
     * Retrieve customer first name
     *
     * @return string
     */
    private function _getCustomerFirstname(Order $order)
    {
        if ($order->getCustomerFirstname()) {
            $customerFirstname = $order->getCustomerFirstname();
        } else {
            $customerFirstname = $order->getBillingAddress()->getFirstname();
        }

        return $customerFirstname;
    }
}
