<?php
namespace Payflex\Gateway\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\DataObject\Factory as DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\ConfigurationFactory;
use Payflex\Gateway\Model\RequestTokenFactory;
use Payflex\Gateway\Service\OrderFinalizer;

/**
 * Assorted order/quote/widget helpers used by checkout, cron and admin.
 * Order finalisation itself lives in {@see OrderFinalizer} so the checkout and
 * cron paths share one implementation.
 */
class PaymentUtil extends AbstractHelper
{
    /** @var Communication */
    private $communicationHelper;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var Configuration */
    private $configHelper;

    /** @var OrderCollectionFactory */
    private $orderCollectionFactory;

    /** @var QuoteFactory */
    private $quoteFactory;

    /** @var OrderFactory */
    private $orderFactory;

    /** @var UrlInterface */
    private $urlBuilder;

    /** @var DataObjectFactory */
    private $dataObjectFactory;

    /** @var ConfigurationFactory */
    private $configurationFactory;

    /** @var RequestTokenFactory */
    private $requestTokenFactory;

    /** @var OrderFinalizer */
    private $orderFinalizer;

    public function __construct(
        Context $context,
        PayflexLogger $logger,
        Communication $communicationHelper,
        Configuration $configHelper,
        StoreManagerInterface $storeManager,
        OrderCollectionFactory $orderCollectionFactory,
        QuoteFactory $quoteFactory,
        OrderFactory $orderFactory,
        UrlInterface $urlBuilder,
        DataObjectFactory $dataObjectFactory,
        ConfigurationFactory $configurationFactory,
        RequestTokenFactory $requestTokenFactory,
        OrderFinalizer $orderFinalizer
    ) {
        parent::__construct($context);
        $this->_logger = $logger;
        $this->communicationHelper = $communicationHelper;
        $this->configHelper = $configHelper;
        $this->storeManager = $storeManager;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->quoteFactory = $quoteFactory;
        $this->orderFactory = $orderFactory;
        $this->urlBuilder = $urlBuilder;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->configurationFactory = $configurationFactory;
        $this->requestTokenFactory = $requestTokenFactory;
        $this->orderFinalizer = $orderFinalizer;
    }

    public function buildRedirectUrl()
    {
        $url = $this->urlBuilder->getUrl('payflex/order/redirect', ['_secure' => true]);
        $this->_logger->info(__METHOD__ . " url: {$url} ");
        return $url;
    }

    public function saveInvalidRefundResponse($payment, $responseText)
    {
        $this->_logger->info(__METHOD__ . " responseText:{$responseText}");
        $info = ["Error" => $responseText];
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

        $order = $this->orderFactory->create()->loadByAttribute("entity_id", $orderId);
        $orderIncrementId = $order->getIncrementId();
        if (!isset($orderIncrementId)) {
            return null;
        }
        return $order;
    }

    public function loadCustomerInfo($order)
    {
        $customerId = $order->getCustomerId();
        $this->_logger->info(__METHOD__ . " customerId:{$customerId}");
        $customerInfo = $this->dataObjectFactory->create();

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
        if (isset($info["orderId"])) {
            $payflexId = $info["orderId"];
            $this->_logger->info(__METHOD__ . "order:{$orderIncrementId} PayflexID: {$payflexId}");
            return $payflexId;
        }
        $this->_logger->info(__METHOD__ . " PayflexID not found");
        return "";
    }

    public function getWidgetHtml($totalAmount)
    {
        if (!$this->configHelper->getEnabled()) {
            return false;
        }

        $storeId = $this->storeManager->getStore()->getId();
        $configurationModel = $this->configurationFactory->create()->load($storeId, "store_id");

        if ($configurationModel && !$configurationModel->getId()) {
            $configurationModel = $this->refreshMerchantConfiguration($configurationModel, $storeId);
        }

        if ($configurationModel->getMin() && $configurationModel->getMax()) {
            if (!$this->configHelper->getProductWidget($storeId)) {
                return;
            }

            $merchantName = $this->configHelper->getMerchantName($storeId) ?: 'yourmerchantname';
            $merchantName = urlencode(str_replace(' ', '-', $merchantName));

            $base = 'https://widgets.payflex.co.za/';
            $query = 'payflex-widget-2.0.1.js?type=calculator&min=' . $configurationModel->getMin()
                . '&max=' . $configurationModel->getMax() . '&amount=' . $totalAmount;

            if ($merchantName && $merchantName != 'yourmerchantname') {
                return '<script async src="' . $base . $merchantName . '/' . $query . '" type="application/javascript"></script>';
            }

            return '<script async src="' . $base . $query . '" type="application/javascript"></script>';
        }
        return false;
    }

    /**
     * Refresh the per-store merchant configuration (spend limits, refund flag)
     * from the Payflex API. Mainly driven by the cron.
     *
     * @param \Payflex\Gateway\Model\Configuration $merchantConfigurationModel
     * @param int $storeId
     * @return \Payflex\Gateway\Model\Configuration
     */
    public function refreshMerchantConfiguration($merchantConfigurationModel, $storeId)
    {
        $apiData = $this->communicationHelper->getMerchantConfiguration($storeId);

        $merchantConfigurationModel->addData([
            "store_id"        => $storeId,
            "min"             => $apiData['minimumAmount'] ?? '',
            "max"             => $apiData['maximumAmount'] ?? '',
            "refunds_enabled" => $apiData['enabledForRefunds'] ?? '',
        ]);

        $merchantConfigurationModel->save();
        return $merchantConfigurationModel;
    }

    /**
     * Reconciliation cron: find pending Payflex orders that are 30 min - 24 h old,
     * re-check their live status and finalise or cancel them via OrderFinalizer.
     */
    public function checkOrderStatus($storeId)
    {
        $pMethod = 'payflex_gateway';

        $this->_logger->info(__METHOD__ . ' for Store ID:' . $storeId);
        $orderFromDateTime = date("Y-m-d H:i:s", strtotime('-24 hours'));
        $orderToDateTime   = date("Y-m-d H:i:s", strtotime('-30 minutes'));
        $ocf = $this->orderCollectionFactory->create();
        $ocf->addAttributeToSelect('entity_id');
        $ocf->addAttributeToSelect('increment_id');
        $ocf->addAttributeToSelect('store_id');
        $ocf->addAttributeToFilter('status', ['eq' => 'pending_payment']);
        $ocf->addFieldToFilter('created_at', ['from' => $orderFromDateTime, 'to' => $orderToDateTime]);
        $ocf->getSelect()
            ->join(
                ["sop" => "sales_order_payment"],
                'main_table.entity_id = sop.parent_id',
                ['method']
            )
            ->where('sop.method = ?', $pMethod);
        $ocf->setOrder('increment_id', 'desc');

        $numberOfOrders = $ocf->getSize();
        $this->_logger->info(__METHOD__ . ' Number of orders:' . $numberOfOrders);
        echo "Payflex: CRON: Checking " . $numberOfOrders . " order(s)" . PHP_EOL;

        foreach ($ocf->getData() as $row) {
            $orderIncrementId = $row['increment_id'];
            $rowStoreId = $row['store_id'];

            $requestToken = $this->requestTokenFactory->create()->load($orderIncrementId, "order_id");
            if (!$requestToken->getpayflexId()) {
                $this->_logger->info(__METHOD__ . "Order does not exist in RequestToken Model");
                throw new LocalizedException(__('The order no longer exists.'));
            }

            $payflexOrderId = $requestToken->getpayflexId();
            $payflexApiResponse = $this->communicationHelper->getTransactionStatus($payflexOrderId, $rowStoreId);
            if (!$payflexApiResponse) {
                throw new NotFoundException(__('Transaction status checking response format is incorrect.'));
            }

            $status = $payflexApiResponse["orderStatus"] ?? null;
            echo "Payflex: API Response for Order ID: " . $orderIncrementId . " " . $status . PHP_EOL;

            $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
            if (!$order->getId()) {
                continue;
            }

            if ($status == "Approved" && ($payflexApiResponse["merchantReference"] ?? null) == $orderIncrementId) {
                $order->addStatusHistoryComment(__('Payflex CRON: Payment was successful for order ID: ' . $payflexOrderId))
                    ->setIsCustomerNotified(true)
                    ->save();
                $this->orderFinalizer->capture($order, $payflexApiResponse);
            } elseif (in_array($status, ["Declined", "Abandoned"], true)) {
                echo "Payflex: Order ID: " . $orderIncrementId . " " . $status . PHP_EOL;
                $this->orderFinalizer->cancel(
                    $order,
                    'Payflex CRON: Transaction ' . strtolower($status) . ' from Payflex window, Order ID:' . $payflexOrderId
                );
            } elseif ($status == "Created") {
                $this->_logger->info(__METHOD__ . " Order {$orderIncrementId} is still 'Created'; will be re-checked next run.");
            }
        }

        $this->_logger->info(__METHOD__ . " Payflex order status cron has executed for store ID " . $storeId . ".");
    }

    public function loadQuote($quoteId)
    {
        $this->_logger->info(__METHOD__ . " QuoteId:{$quoteId}");

        $quote = $this->quoteFactory->create()->loadByIdWithoutStore($quoteId);
        if (!$quote->getId()) {
            $this->_logger->critical("Failed to load quote : {$quoteId}");
            return null;
        }
        return $quote;
    }
}
