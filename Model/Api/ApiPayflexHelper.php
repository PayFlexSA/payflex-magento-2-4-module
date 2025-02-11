<?php
namespace Payflex\Gateway\Model\Api;

use \Magento\Framework\Exception\State\InvalidTransitionException;

class ApiPayflexHelper
{

    /**
     *
     * @var \Payflex\Gateway\Model\Api\ApiCommonHelper
     */
    private $_apiCommonHelper;
    
    /**
     *
     * @var \Payflex\Gateway\Helper\Payflex\UrlCreator
     */
    private $_payflexUrlCreator;
    /**
     *
     * @var \Payflex\Gateway\Logger\PayflexLogger
     */
    private $_logger;

    public function __construct()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_payflexUrlCreator = $objectManager->get("\Payflex\Gateway\Helper\Payflex\UrlCreator");
        $this->_apiCommonHelper = $objectManager->get("\Payflex\Gateway\Model\Api\ApiCommonHelper");
        
        $this->_logger = $objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        
        $this->_logger->info(__METHOD__);
    }

    public function createUrlForCustomer($quoteId, \Magento\Quote\Api\Data\PaymentInterface $method)
    {
        $this->_logger->info(__METHOD__. " quoteId:{$quoteId}");

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->_apiCommonHelper->setPaymentForLoggedinCustomer($quoteId, $method);
        
        return $this->_createUrl($quote);
    }
    
    public function createUrlForGuest($cartId, $email, \Magento\Quote\Api\Data\PaymentInterface $method)
    {
        $this->_logger->info(__METHOD__. " cartId:{$cartId}");

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->_apiCommonHelper->setPaymentForGuest($cartId, $email, $method);
        
        return $this->_createUrl($quote);
    }
    
    private function _createUrl(\Magento\Quote\Model\Quote $quote)
    {
        // Create payflex redirect url.
        $url = $this->_payflexUrlCreator->CreateUrl($quote);
        $this->_logger->info(__METHOD__. " QUOTE:{".json_encode($quote)."}");
        if (!isset($url) || empty($url)){
            $quoteId = $quote->getId();
            $this->_logger->critical(__METHOD__ . " Failed to create transaction quoteId:{$quoteId}");
            throw new InvalidTransitionException(__('Failed to create transaction, CreateUrl() returned nothing'));
        }
        
        $this->_logger->info(__METHOD__. " redirectUrl:{$url}");
        return $url;
    }
}
