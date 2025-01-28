<?php

// Magento\Framework\DataObject implements the magic call function

// Create payment module http://www.josephmcdermott.co.uk/basics-creating-magento2-payment-method
// https://github.com/magento/magento2-samples/tree/master/sample-module-payment-provider
namespace Payflex\Gateway\Model;

class PaymentHelper
{
    /**
     *
     * @var \Payflex\Gateway\Helper\PaymentUtil
     */
    protected $_paymentUtil;
    private $_objectManager;
    /**
     *
     * @var \Payflex\Gateway\Helper\Configuration
     */
    protected $_configuration;

    protected $_logger;

    /**
     *
     * @var \Payflex\Gateway\Helper\Communication
     */
    protected $_communication;

	public function __construct()
	{
		$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_paymentUtil = $objectManager->get("\Payflex\Gateway\Helper\PaymentUtil");
        $this->_communication = $objectManager->get("\Payflex\Gateway\Helper\Communication");
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
		$this->_logger->info(__METHOD__);
	}


	public function init($configuration, $communication)
	{
		$this->_logger->info(__METHOD__);

	    $this->_configuration = $configuration;
	    $this->_communication = $communication;
	}

	public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
	{
	    $this->_logger->info(__METHOD__);
	    if ($quote != null){
	        $enabled = $this->_configuration->getEnabled($quote->getStoreId());
	    }
	    else {
	        $enabled = $this->_configuration->getEnabled();
	    }
	    $this->_logger->info(__METHOD__ . " enabled:" . $enabled);
	    return $enabled;
	}

	public function getConfigPaymentAction($storeId)
    {
        // invoked by Magento\Sales\Model\Order\Payment::place
        $this->_logger->info(__METHOD__);
        $paymentAction = \Magento\Payment\Model\Method\AbstractMethod::ACTION_AUTHORIZE_CAPTURE;
        $this->_logger->info(__METHOD__ . " paymentAction: {$paymentAction}");
        return $paymentAction;
    }

	public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount, $storeId)
    {
        // refer to Magento\Sales\Model\Order\Payment\Transaction\Builder::build for which fields should be set.
        $this->_logger->info(__METHOD__);

        $orderId = "unknown";
        $order = $payment->getOrder();
        if ($order) {
            $orderId = $order->getIncrementId();
        }

        if (!$payment->hasAdditionalInformation()) {
            $this->_logger->info(__METHOD__ . " orderId:{$orderId} additional_information is empty");
        }
        $info = $payment->getAdditionalInformation();
        if(isset($info["orderId"])){
            $transactionId = $info["orderId"]; // ensure it is unique
            $payment->setTransactionId($transactionId);
            $payment->setIsTransactionClosed(1);
            unset($info['items']);
            unset($info['billing']);
            unset($info['shipping']);
            unset($info['merchant']);
            $payment->setTransactionAdditionalInfo(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS, $info);
        }
    }

    // Mage_Sales_Model_Order_Payment::refund
    // use getInfoInstance to get object of Mage_Payment_Model_Info (Mage_Payment_Model_Info::getMethodInstance Mage_Sales_Model_Order_Payment is sub class of Mage_Payment_Model_Info)
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount, $storeId)
    {
        $this->_logger->info(__METHOD__);
        $info = $payment->getAdditionalInformation();
        $orderIncrementId = $payment->getOrder()->getIncrementId();
        $requestTokenManager = $this->_objectManager->create("\Payflex\Gateway\Model\RequestToken");
        $requestToken = $requestTokenManager->load($orderIncrementId, "order_id");
        $payflexId = $requestToken->getpayflexId();
        // $payflexId = $this->_paymentUtil->findPayflexOrderForRefund($orderIncrementId, $info);
        $apiResult = $this->_communication->refund($orderIncrementId, $payflexId, $amount, $storeId);

        $orderId = "unknown";
        $order = $payment->getOrder();
        if ($order) {
            $orderId = $order->getIncrementId();
        }
        $this->_logger->info(__METHOD__ . " orderId:{$orderId}");

        $response = $apiResult['response'];
        if ($apiResult['errmsg']) {
            $errorMessage = " Failed to refund order:{$orderId}, {$apiResult['errmsg']}, response from PayFlex: " . $response;
            $this->_paymentUtil->saveInvalidRefundResponse($payment, $errorMessage);
            $this->_logger->critical(__METHOD__ . $errorMessage);
            throw new \Magento\Framework\Exception\PaymentException(__($errorMessage));
        }

        $this->_paymentUtil->savePayflexRefundResponse($payment, $response);
    }
}
