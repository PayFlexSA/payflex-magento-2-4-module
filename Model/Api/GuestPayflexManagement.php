<?php
namespace Payflex\Gateway\Model\Api;

class GuestPayflexManagement implements \Payflex\Gateway\Api\GuestPayflexManagementInterface
{
    // http://devdocs.magento.com/guides/v2.0/extension-dev-guide/service-contracts/service-to-web-service.html
    
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
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private $_cartRepository;
    
    /**
     * @var \Magento\Quote\Model\QuoteIdMaskFactory
     */
    private $_quoteIdMaskFactory;
    
    /**
     * @var \Magento\Quote\Api\GuestBillingAddressManagementInterface
     */
    private $billingAddressManagement;
    
    
    public function __construct(
    		\Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
    		\Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory,
    		\Magento\Quote\Api\GuestBillingAddressManagementInterface $billingAddressManagement
    ) {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_apiHelper = $objectManager->get("\Payflex\Gateway\Model\Api\ApiPayflexHelper");
        $this->_logger = $objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_logger->info(__METHOD__);
        
        $this->_cartRepository = $quoteRepository;
        $this->_quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->billingAddressManagement = $billingAddressManagement;
    }

    /**
     * {@inheritDoc}
     */
    public function set(
    		$cartId, 
    		$email, 
    		\Magento\Quote\Api\Data\PaymentInterface $method, 
    		\Magento\Quote\Api\Data\AddressInterface $billingAddress = null
    ) {
        $this->_logger->info(__METHOD__. " cartId:{$cartId} guestEmail:{$email}");
        // Create payflex redirect url.
        
        if ($billingAddress) {
        	$this->_logger->info(__METHOD__. " assigning billing address.");
        	$billingAddress->setEmail($email);
        	$this->billingAddressManagement->assign($cartId, $billingAddress);
        } else {
        	$quoteIdMask = $this->_quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        	$quoteId = $quoteIdMask->getQuoteId();
        	$this->_cartRepository->getActive($quoteId)->getBillingAddress()->setEmail($email);
        }
        
        $url = $this->_apiHelper->createUrlForGuest($cartId, $email, $method);
        
        $this->_logger->info(__METHOD__. " redirectUrl:{$url}");
        return $url;
    }

}
