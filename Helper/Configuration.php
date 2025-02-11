<?php

namespace Payflex\Gateway\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\Order;

class Configuration extends AbstractHelper
{
    const payflex_PATH = "payment/payflex_gateway/";
    const MODULE_NAME = "Payflex_Gateway";

    /**
     *
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    private $_moduleList;

    public function __construct(Context $context)
    {
        parent::__construct($context);
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_moduleList = $objectManager->get("Magento\Framework\Module\ModuleListInterface");
        $this->_logger = $objectManager->get("Payflex\Gateway\Logger\PayflexLogger");
    }

    public function getPaymentType($storeId = null)
    {
        return 'Purchase';
    }

    public function getModuleVersion()
    {
        if ($this->_moduleList == null)
            return "M2-unknown";
        return "M2-" . $this->_moduleList->getOne(self::MODULE_NAME)['setup_version'];
    }

    public function getPayflexClientId($storeId = null)
    {
        return $this->_getPayflexStoreConfig("client_id", $storeId);
    }

    public function getPayflexClientSecret($storeId = null)
    {
        return $this->_getPayflexStoreConfig("client_secret", $storeId, true);
    }

    public function getPayflexApiEndpoint($storeId = null)
    {
        return $this->_getPayflexStoreConfig("api_endpoint", $storeId);
    }
    public function getPayflexEnvironment($storeId = null)
    {
        return $this->_getPayflexStoreConfig("payflex_environment", $storeId);
    }

    public function getEnabled($storeId = null)
    {
        return filter_var($this->_getPayflexStoreConfig("active", $storeId), FILTER_VALIDATE_BOOLEAN);
    }

    public function getDebugFlag($storeId = null)
    {
        return filter_var($this->_getPayflexStoreConfig("debug_flag", $storeId), FILTER_VALIDATE_BOOLEAN);
    }

    public function getMerchantName($storeId = null)
    {
        return $this->_getPayflexStoreConfig("merchant_name", $storeId);
    }
    public function getProductWidget($storeId = null)
    {
        return $this->_getPayflexStoreConfig("product_widget", $storeId);
    }
    public function getInvoiceEmail($storeId = null)
    {
        return $this->_getPayflexStoreConfig("invoice_email", $storeId);
    }
    public function getOrderEmail($storeId = null)
    {
        return $this->_getPayflexStoreConfig("order_email", $storeId);
    }
    public function getPayflexNewOrderStatus($storeId = null)
    {
        $status = $this->_getPayflexStoreConfig("new_order_status", $storeId);
        if(empty($status) OR is_null($status))
            return Order::STATE_PROCESSING;
        
        return $status;
    }
    public function getPayflexNewOrderState($storeId = null)
    {
        $state = $this->_getPayflexStoreConfig("new_order_state", $storeId);
        if(empty($state) OR is_null($state))
            return Order::STATE_PROCESSING;
        
        return $state;
    }
    public function versionCheck($min_version, $max_version = false)
    {
        $version = $this->getMagentoVersion();
        
        # Remove the patch version
        $version = preg_replace('/\.\d+\.\d+/', '', $version);

        if (version_compare($version, $min_version, '<'))                 return false;
        if ($max_version && version_compare($version, $max_version, '>')) return false;

        return true;
    }
    public function getMagentoVersion()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
        return $productMetadata->getVersion();
    }
    private function _getPayflexStoreConfig($configName, $storeId = null, $isSensitiveData = false)
    {
        $this->_logger->info("Configuration::_getPayflexStoreConfig storeId argument:" . $storeId);

        $value = $this->scopeConfig->getValue(self::payflex_PATH . $configName, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);

        if (!$isSensitiveData) {
            $this->_logger->info(__METHOD__ . " configName:{$configName} storeId:{$storeId} value:{$value}");
        } else {
            $this->_logger->info(__METHOD__ . " configName:{$configName} storeId:{$storeId} value:*****");
        }
        return $value;
    }
}
