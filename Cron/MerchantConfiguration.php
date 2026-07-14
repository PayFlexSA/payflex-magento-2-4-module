<?php

namespace Payflex\Gateway\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Payflex\Gateway\Helper\PaymentUtil;
use Payflex\Gateway\Model\ConfigurationFactory;

/**
 * Refreshes the per-store merchant configuration (spend limits, refund flag)
 * from the Payflex API.
 */
class MerchantConfiguration
{
    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var PaymentUtil */
    private $paymentUtil;

    /** @var ConfigurationFactory */
    private $configurationFactory;

    public function __construct(
        StoreManagerInterface $storeManager,
        PaymentUtil $paymentUtil,
        ConfigurationFactory $configurationFactory
    ) {
        $this->storeManager = $storeManager;
        $this->paymentUtil = $paymentUtil;
        $this->configurationFactory = $configurationFactory;
    }

    public function execute()
    {
        foreach (array_keys($this->storeManager->getStores()) as $storeId) {
            $configurationModel = $this->configurationFactory->create()->load($storeId, "store_id");
            $this->paymentUtil->refreshMerchantConfiguration($configurationModel, $storeId);
        }
    }
}
