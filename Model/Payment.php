<?php

// Magento\Framework\DataObject implements the magic call function

namespace Payflex\Gateway\Model;

class Payment extends \Magento\Payment\Model\Method\AbstractMethod
{
    // NB: $_logger is declared (protected) by AbstractModel; we reuse it for the
    // Payflex logger rather than redeclaring it.

    protected $_code;
    
    protected $_infoBlockType = 'Payflex\Gateway\Block\Info';

    protected $_isGateway = true;

    protected $_canCapture = true;

    protected $_canUseInternal = false;

    protected $_canUseCheckout = true;

    protected $_canUseForMultishipping = false;

    protected $_canRefund = true;

    protected $_canCapturePartial = true;

    protected $_canRefundInvoicePartial = true;

    protected $_isInitializeNeeded = true;

    protected $communication;
    /**
     * 
     * @var \Payflex\Gateway\Model\PaymentHelper
     */
    private $_paymentHelper;

    const Payflex_Gateway_CODE = "payflex_gateway";

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Payflex\Gateway\Logger\PayflexLogger $payflexLogger,
        \Payflex\Gateway\Model\PaymentHelper $paymentHelper,
        \Payflex\Gateway\Helper\Communication $communication,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    )
    {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->_code = self::Payflex_Gateway_CODE;
        $this->_logger = $payflexLogger;
        $this->_paymentHelper = $paymentHelper;
        $this->communication = $communication;
    }

     /**
     * Instantiate state and set it to state object
     *
     * @param string $paymentAction
     * @param \Magento\Framework\DataObject $stateObject
     * @return void
     */
    public function initialize($paymentAction, $stateObject)
    {
        /** @var \Magento\Payment\Model\InfoInterface|\Payflex\Gateway\Model\Payment $payment */
        $payment = $this->getInfoInstance();
        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);
        $payment->setAmountAuthorized($order->getTotalDue());
        $payment->setBaseAmountAuthorized($order->getBaseTotalDue());


        $this->_logger->info(__METHOD__);
        $stateObject->setState( \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT );
        $stateObject->setStatus( 'pending_payment' );
        $stateObject->setIsNotified(false);
        
    }
    
    // invoked by Magento\Quote\Model\PaymentMethodManagement::set
    public function assignData(\Magento\Framework\DataObject $data)
    {
        $this->_logger->info(__METHOD__);
        $infoInstance = $this->getInfoInstance();

        // Additional information is (re)built later in the session-creation flow
        // (see ApiCommonHelper / UrlCreator), so we start it clean here.
        $infoInstance->setAdditionalInformation([]);
        $infoInstance->save();

        return $this;
    }
    
    public function getConfigPaymentAction()
    {
        return $this->_paymentHelper->getConfigPaymentAction($this->getStore());
    }

    // Invoked by Mage_Sales_Model_Order_Payment::capture
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->_logger->info(__METHOD__ . " payment amount:" . $amount);
        $this->_paymentHelper->capture($payment, $amount, $this->getStore());
        return $this;
    }
    
    // Mage_Sales_Model_Order_Payment::refund
    // use getInfoInstance to get object of Mage_Payment_Model_Info (Mage_Payment_Model_Info::getMethodInstance Mage_Sales_Model_Order_Payment is sub class of Mage_Payment_Model_Info)
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->_logger->info(__METHOD__);
        $this->_paymentHelper->refund($payment, $amount, $this->getStore());
        return $this;
    }

    public function isAvailable(?\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        $this->_logger->info(__METHOD__);
        return $this->_paymentHelper->isAvailable($quote);
    }

    public function canRefund()
    {
        $this->_logger->info(__METHOD__);

        $storeId = $this->getStore();
        $limits = $this->communication->getMerchantConfiguration($storeId);

        $enabledForRefunds = $limits['enabledForRefunds'] ?? false;

        if ($enabledForRefunds) return true;

        return false;
    }

}
