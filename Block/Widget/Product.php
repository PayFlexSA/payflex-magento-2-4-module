<?php
namespace Payflex\Gateway\Block\Widget;

use Magento\Framework\View\Element\Template\Context;

class Product extends \Magento\Framework\View\Element\Template
{

    /**
     * @var $_paymentUtil \Payflex\Gateway\Helper\PaymentUtil
     */
    protected $_paymentUtil;

    /**
     * @var \Magento\Framework\Registry
     */
    protected $_coreRegistry;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $_productRepository;

    public function __construct(
        Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        array $data = []
    ){
        parent::__construct($context, $data);
        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_paymentUtil = $objectManager->get("\Payflex\Gateway\Helper\PaymentUtil");
        $this->_coreRegistry = $registry;
        $this->_productRepository = $productRepository;
        $this->_logger->info('Product:'.__METHOD__);
    }

    public function getProductWidgetHtml(\Magento\Catalog\Model\Product $product = null)
    {
        if (!$product){
            if (!($product = $this->_coreRegistry->registry('product'))) {
                $productId = (int) $this->getRequest()->getParam('id');
                $product = $this->_productRepository->getById($productId);
                $this->_coreRegistry->register('product', $product);
            }
        }

        return $this->_paymentUtil->getWidgetHtml($product->getFinalPrice());
    }
}
