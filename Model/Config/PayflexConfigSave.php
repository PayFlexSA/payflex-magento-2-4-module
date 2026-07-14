<?php
/**
 * Intercept the config save action to refresh Payflex credentials and merchant
 * configuration after the admin saves the payment settings.
 */

namespace Payflex\Gateway\Model\Config;

use Magento\Config\Controller\Adminhtml\System\Config\Save as ConfigSave;
use Magento\Store\Model\StoreManagerInterface;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Helper\PaymentUtil;
use Payflex\Gateway\Model\ConfigurationFactory;

class PayflexConfigSave
{
    /** @var Communication */
    private $communication;

    /** @var PaymentUtil */
    private $paymentUtil;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var ConfigurationFactory */
    private $configurationFactory;

    public function __construct(
        Communication $communication,
        PaymentUtil $paymentUtil,
        StoreManagerInterface $storeManager,
        ConfigurationFactory $configurationFactory
    ) {
        $this->communication = $communication;
        $this->paymentUtil = $paymentUtil;
        $this->storeManager = $storeManager;
        $this->configurationFactory = $configurationFactory;
    }

    public function beforeExecute(ConfigSave $subject)
    {
        return null;
    }

    public function afterExecute(ConfigSave $subject, $result)
    {
        // Credentials may have changed: drop the cached token and re-pull the
        // merchant configuration for every store.
        $this->communication->forceRefeshToken();

        foreach (array_keys($this->storeManager->getStores()) as $storeId) {
            $configurationModel = $this->configurationFactory->create()->load($storeId, "store_id");
            $this->paymentUtil->refreshMerchantConfiguration($configurationModel, $storeId);
        }

        return $result;
    }
}
