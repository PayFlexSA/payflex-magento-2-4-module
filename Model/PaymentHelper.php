<?php

// Magento\Framework\DataObject implements the magic call function

namespace Payflex\Gateway\Model;

use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Helper\PaymentUtil;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\RequestTokenFactory;

class PaymentHelper
{
    /** @var PaymentUtil */
    protected $_paymentUtil;

    /** @var Configuration */
    protected $_configuration;

    /** @var PayflexLogger */
    protected $_logger;

    /** @var Communication */
    protected $_communication;

    /** @var RequestTokenFactory */
    private $requestTokenFactory;

	public function __construct(
        PayflexLogger $logger,
        PaymentUtil $paymentUtil,
        Communication $communication,
        Configuration $configuration,
        RequestTokenFactory $requestTokenFactory
    ) {
        $this->_logger = $logger;
        $this->_paymentUtil = $paymentUtil;
        $this->_communication = $communication;
        $this->_configuration = $configuration;
        $this->requestTokenFactory = $requestTokenFactory;
	}

	public function isAvailable(?\Magento\Quote\Api\Data\CartInterface $quote = null)
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

    public function capture(\Magento\Sales\Model\Order\Payment $payment, $amount, $storeId)
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
    public function refund(\Magento\Sales\Model\Order\Payment $payment, $amount, $storeId)
    {
        $this->_logger->info(__METHOD__);
        $info                = $payment->getAdditionalInformation();
        $orderIncrementId    = $payment->getOrder()->getIncrementId();
        $requestToken        = $this->requestTokenFactory->create()->load($orderIncrementId, "order_id");
        $payflexId           = $requestToken->getpayflexId();
        // $payflexId = $this->_paymentUtil->findPayflexOrderForRefund($orderIncrementId, $info);
        $apiResult = $this->_communication->refund($orderIncrementId, $payflexId, $amount, $storeId);

        $orderId = "unknown";
        $order   = $payment->getOrder();
        if ($order) {
            $orderId = $order->getIncrementId();
        }
        $this->_logger->info(__METHOD__ . " orderId:{$orderId}");

        $response = $apiResult['response'];
        if ($apiResult['errmsg']) {
            $errorMessage = " Failed to refund order:{$orderId}, {$apiResult['errmsg']}, response from Payflex: " . $response;
            $this->_paymentUtil->saveInvalidRefundResponse($payment, $errorMessage);
            $this->_logger->critical(__METHOD__ . $errorMessage);
            throw new \Magento\Framework\Exception\PaymentException(__($errorMessage));
        }

        $this->_paymentUtil->savePayflexRefundResponse($payment, $response);
    }
}
