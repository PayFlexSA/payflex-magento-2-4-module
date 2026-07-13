<?php
/**
 * Admin AJAX endpoint: reconcile a Magento order with its live Payflex status.
 *
 * Mutating — invoices an approved order or cancels a declined/abandoned one,
 * mirroring the status cron. POST only, protected by the admin form key.
 * URL: payflex/order/updateStatus
 */

namespace Payflex\Gateway\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\OrderStatusManager;

class UpdateStatus extends Action
{
    /**
     * ACL: only users who can view/manage orders may reconcile them.
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

        if (!$this->getRequest()->isPost()) {
            return $result->setData(['success' => false, 'message' => (string) __('Invalid request method.')]);
        }

        $orderId = (int) $this->getRequest()->getParam('order_id');
        if (!$orderId) {
            return $result->setData(['success' => false, 'message' => (string) __('Missing order id.')]);
        }

        try {
            $data = $this->statusManager->applyStatus($orderId);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' ' . $e->getMessage());
            $data = ['success' => false, 'message' => (string) __('Could not update the order.')];
        }

        return $result->setData($data);
    }
}
