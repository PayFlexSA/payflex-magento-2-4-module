<?php
/**
 * Admin AJAX endpoint: look up the live Payflex status for an order.
 *
 * Read-only — performs no changes to the order. URL: payflex/order/checkStatus
 */

namespace Payflex\Gateway\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\OrderStatusManager;

class CheckStatus extends Action
{
    /**
     * ACL: only users who can view orders may query Payflex status.
     */
    const ADMIN_RESOURCE = 'Magento_Sales::sales_order';

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var OrderStatusManager
     */
    private $statusManager;

    /**
     * @var PayflexLogger
     */
    private $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderStatusManager $statusManager,
        PayflexLogger $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->statusManager = $statusManager;
        $this->logger = $logger;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $orderId = (int) $this->getRequest()->getParam('order_id');

        if (!$orderId) {
            return $result->setData(['success' => false, 'message' => (string) __('Missing order id.')]);
        }

        try {
            $data = $this->statusManager->checkStatus($orderId);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' ' . $e->getMessage());
            $data = ['success' => false, 'message' => (string) __('Could not check the Payflex status.')];
        }

        return $result->setData($data);
    }
}
