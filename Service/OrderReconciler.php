<?php

namespace Payflex\Gateway\Service;

use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\RequestTokenFactory;

/**
 * Reads the live Payflex status for an order and applies it. Shared by the
 * webhook, the reconciliation cron and the admin reconcile button so that they
 * all reach the same outcome for the same order.
 */
class OrderReconciler
{
    /** Another process is already reconciling this order. */
    const RESULT_BUSY = 'busy';

    const LOCK_PREFIX = 'PAYFLEX_ORDER_';

    /** @var PayflexLogger */
    private $logger;

    /** @var Communication */
    private $communication;

    /** @var OrderFinalizer */
    private $orderFinalizer;

    /** @var RequestTokenFactory */
    private $requestTokenFactory;

    /** @var LockManagerInterface */
    private $lockManager;

    public function __construct(
        PayflexLogger $logger,
        Communication $communication,
        OrderFinalizer $orderFinalizer,
        RequestTokenFactory $requestTokenFactory,
        LockManagerInterface $lockManager
    ) {
        $this->logger = $logger;
        $this->communication = $communication;
        $this->orderFinalizer = $orderFinalizer;
        $this->requestTokenFactory = $requestTokenFactory;
        $this->lockManager = $lockManager;
    }

    /**
     * Look the order up at Payflex and finalise it accordingly. Returns
     * RESULT_BUSY, or one of the OrderFinalizer RESULT_* constants.
     *
     * @throws NotFoundException when the status could not be read from Payflex.
     */
    public function reconcile(Order $order, string $source): string
    {
        $lockName = self::LOCK_PREFIX . $order->getIncrementId();

        if (!$this->lockManager->lock($lockName, 0)) {
            $this->logger->info(__METHOD__ . " order {$order->getIncrementId()} is locked by another process");
            return self::RESULT_BUSY;
        }

        try {
            return $this->applyLiveStatus($order, $source);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * The Payflex order id for a Magento order, or null when it is not known.
     */
    public function getPayflexId(Order $order)
    {
        $payment = $order->getPayment();
        if ($payment) {
            $info = (array) $payment->getAdditionalInformation();
            foreach (['orderId', 'payflex_id'] as $key) {
                if (!empty($info[$key])) {
                    return (string) $info[$key];
                }
            }
        }

        $token = $this->requestTokenFactory->create()->load($order->getIncrementId(), 'order_id');
        if ($token->getPayflexId()) {
            return (string) $token->getPayflexId();
        }

        return null;
    }

    /**
     * The decoded Payflex order response, or an empty array when it could not
     * be read. Never throws, so it is safe for read-only callers.
     */
    public function fetchStatus(Order $order): array
    {
        $payflexId = $this->getPayflexId($order);
        if ($payflexId === null) {
            $this->logger->info(__METHOD__ . ' no Payflex order id for order ' . $order->getIncrementId());
            return [];
        }

        return $this->fetchStatusById($payflexId, $order);
    }

    private function fetchStatusById(string $payflexId, Order $order): array
    {
        try {
            $response = $this->communication->getTransactionStatus($payflexId, $order->getStoreId());
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' ' . $e->getMessage());
            return [];
        }

        if (!is_array($response)) {
            return [];
        }

        return $response;
    }

    /**
     * Record the latest Payflex status on the payment and return the timestamp
     * that was stored.
     */
    public function storeStatus(Order $order, $status): string
    {
        $now = date('Y-m-d H:i:s');

        $payment = $order->getPayment();
        if (!$payment) {
            return $now;
        }

        try {
            $payment->setAdditionalInformation('orderStatus', $status);
            $payment->setAdditionalInformation('payflex_status_checked_at', $now);
            $payment->save();
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' ' . $e->getMessage());
        }

        return $now;
    }

    private function applyLiveStatus(Order $order, string $source): string
    {
        if ($this->orderFinalizer->isFinalized($order)) {
            $this->logger->info(__METHOD__ . " order {$order->getIncrementId()} is already finalised");
            return OrderFinalizer::RESULT_NONE;
        }

        $payflexId = $this->getPayflexId($order);
        if ($payflexId === null) {
            $this->logger->critical(__METHOD__ . " no Payflex order id for order {$order->getIncrementId()}");
            return OrderFinalizer::RESULT_NONE;
        }

        // Throw rather than return, so that a caller that can retry knows the
        // difference between a failed lookup and an order with nothing to do.
        $response = $this->fetchStatusById($payflexId, $order);
        if (empty($response['orderStatus'])) {
            throw new NotFoundException(
                __('Could not read the Payflex status for order %1.', $order->getIncrementId())
            );
        }

        $this->storeStatus($order, $response['orderStatus']);

        return $this->orderFinalizer->finalizeFromStatus($order, $response, $source);
    }
}
