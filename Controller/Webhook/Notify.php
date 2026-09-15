<?php

namespace Payflex\Gateway\Controller\Webhook;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\OrderFactory;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Service\OrderReconciler;

/**
 * Payflex status callback endpoint. The posted body is only used as a trigger:
 * the status that the order is updated from is always read back from the
 * Payflex API, so an unauthenticated caller cannot change an order.
 */
class Notify extends Action implements CsrfAwareActionInterface
{
    /** @var PayflexLogger */
    private $logger;

    /** @var Communication */
    private $communication;

    /** @var OrderReconciler */
    private $orderReconciler;

    /** @var OrderFactory */
    private $orderFactory;

    /** @var JsonFactory */
    private $resultJsonFactory;

    public function __construct(
        Context $context,
        PayflexLogger $logger,
        Communication $communication,
        OrderReconciler $orderReconciler,
        OrderFactory $orderFactory,
        JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
        $this->logger = $logger;
        $this->communication = $communication;
        $this->orderReconciler = $orderReconciler;
        $this->orderFactory = $orderFactory;
        $this->resultJsonFactory = $resultJsonFactory;
    }

    public function execute()
    {
        $orderIncrementId = $this->getRequest()->getParam('mage_order_id');
        $key = $this->getRequest()->getParam('key');

        $this->logger->info(
            __METHOD__ . " order:{$orderIncrementId} body:" . $this->getRequest()->getContent()
        );

        if (!$orderIncrementId || !$key) {
            $this->logger->critical(__METHOD__ . ' callback is missing the order id or the key');
            return $this->respond(200, 'ignored');
        }

        $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
        if (!$order->getId()) {
            $this->logger->critical(__METHOD__ . " callback for unknown order {$orderIncrementId}");
            return $this->respond(200, 'ignored');
        }

        $expectedKey = $this->communication->getWebhookKey($orderIncrementId, $order->getStoreId());
        if (!hash_equals($expectedKey, (string) $key)) {
            $this->logger->critical(__METHOD__ . " callback key does not match for order {$orderIncrementId}");
            return $this->respond(200, 'ignored');
        }

        try {
            $action = $this->orderReconciler->reconcile($order, 'Payflex webhook');
        } catch (\Exception $e) {
            $this->logger->critical(__METHOD__ . " order:{$orderIncrementId} " . $e->getMessage());
            return $this->respond(500, 'error');
        }

        $this->logger->info(__METHOD__ . " order:{$orderIncrementId} action:{$action}");

        if ($action === OrderReconciler::RESULT_BUSY) {
            return $this->respond(503, $action);
        }

        return $this->respond(200, $action);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Payflex retries the callback on any non-2xx response, so only answer with
     * an error code when retrying could actually help.
     */
    private function respond($httpCode, $action)
    {
        $status = 'ok';
        if ($httpCode !== 200) {
            $status = 'retry';
        }

        $result = $this->resultJsonFactory->create();
        $result->setHttpResponseCode($httpCode);
        $result->setData(['status' => $status, 'action' => $action]);

        return $result;
    }
}
