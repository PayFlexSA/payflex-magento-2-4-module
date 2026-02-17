<?php
/**
 * Intercept the config save action to perform custom logic before and after saving the configuration.
 */

namespace Payflex\Gateway\Model\Config;

use Magento\Config\Controller\Adminhtml\System\Config\Save as ConfigSave;
use Payflex\Gateway\Helper\Communication;

class PayflexConfigSave
{
    private $orderCollectionFactory;

    private $objectManager;

    private $timezone;

    private $paymentUtil;

    /**
     * @var \Payflex\Gateway\Helper\Communication
     */
    private $communication;

    public function __construct(
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->objectManager = $objectManager;
        $context = $objectManager->get('Magento\Framework\App\Helper\Context');

        // Get system timezone
        $timezone = $objectManager->create('Magento\Framework\Stdlib\DateTime\TimezoneInterface');
        $timezone->getConfigTimezone();
        $timezone->date();

        $timezone = new \DateTimeZone($timezone->getConfigTimezone());
        $this->timezone = $timezone;

        $date = $objectManager->get('Magento\Framework\Stdlib\DateTime\DateTime');
        $this->communication = new Communication($context, $date);

        $this->paymentUtil = $objectManager->get("\Payflex\Gateway\Helper\PaymentUtil");
    }
    public function beforeExecute(ConfigSave $subject)
    {
        // Do things BEFORE save
        return null;
    }

    public function afterExecute(ConfigSave $subject, $result)
    {
        // Do things AFTER save
        $this->communication->forceRefeshToken();

        // refresh merchant configuration for all stores
        $stores = $this->objectManager->get("\Magento\Store\Model\StoreManagerInterface")->getStores();
        foreach (array_keys($stores) as $storeId){
            $configurationModel = $this->objectManager->create("\Payflex\Gateway\Model\Configuration")->load($storeId, "store_id");
            $this->paymentUtil->refreshMerchantConfiguration($configurationModel, $storeId);
        }

        return $result;
    }
}