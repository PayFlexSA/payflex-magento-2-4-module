<?php

namespace Payflex\Gateway\Cron;

class CheckOrderStatus
{
  /**
   *
   * @var \Magento\Framework\App\ObjectManager
   */
  protected $_objectManager;

  /**
   * @var \Magento\Store\Model\StoreManagerInterface
   */
  protected $_storeManager;

  /**
   * @var \Payflex\Gateway\Helper\PaymentUtil
   */
  protected $_paymentUtil;

  /**
   * @var \Payflex\Gateway\Logger\PayflexLogger
   */
  protected $_logger;

  /**
   * MerchantConfiguration Cron constructor.
   * @param \Magento\Store\Model\StoreManagerInterface $storeManager
   */
  public function __construct(
      \Magento\Store\Model\StoreManagerInterface $storeManager,
      \Payflex\Gateway\Logger\PayflexLogger $logger,
      \Payflex\Gateway\Helper\PaymentUtil $paymentUtil
  ) {
      $this->_storeManager = $storeManager;
      $this->_logger       = $logger;
      $this->_paymentUtil  = $paymentUtil;
      $this->_logger->info('CheckOrderStatus:'.__METHOD__);
  }

  public function execute()
  {
    $stores = $this->_storeManager->getStores();
    foreach (array_keys($stores) as $storeId){
        $this->_paymentUtil->checkOrderStatus($storeId);
        $this->_logger->info(__METHOD__ . " CheckOrderStatus was executing for StoreID:".$storeId);
    }

    $this->_logger->info(__METHOD__ . " CheckOrderStatus was executed.");
  }
}
