<?php
namespace Payflex\Gateway\Model\Api;

class PayflexManagement implements \Payflex\Gateway\Api\PayflexManagementInterface
{
    
    /**
     * 
     * @var \Payflex\Gateway\Model\Api\ApiPayflexHelper
     */
    private $_apiHelper;

    /**
     *
     * @var \Payflex\Gateway\Logger\PayflexLogger
     */
    private $_logger;
    
    /**
     *
     * @var \Magento\Quote\Api\BillingAddressManagementInterface
     */
    private $_billingAddressManagement;


    public function __construct(\Magento\Quote\Api\BillingAddressManagementInterface $billingAddressManagement)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_billingAddressManagement = $billingAddressManagement;
        $this->_apiHelper = $objectManager->get("\Payflex\Gateway\Model\Api\ApiPayflexHelper");
        $this->_logger = $objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        
        $this->_logger->info(__METHOD__);
    }

    /**
     * {@inheritDoc}
     */
    public function set($cartId, \Magento\Quote\Api\Data\PaymentInterface $method, \Magento\Quote\Api\Data\AddressInterface $billingAddress = null)
    {
        $this->_logger->info(__METHOD__. " cartId:{$cartId}");
        
        if ($billingAddress) {
        	$this->_logger->info(__METHOD__. " assigning billing address");
        	$this->_billingAddressManagement->assign($cartId, $billingAddress);
        }
        
        $url = $this->_apiHelper->createUrlForCustomer($cartId, $method);
        $this->_logger->info(__METHOD__. " redirectUrl:{$url}");
        return $url;
    }

}
