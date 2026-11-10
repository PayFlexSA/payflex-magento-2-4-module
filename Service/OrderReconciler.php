<?php

namespace Payflex\Gateway\Service;

use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\ResourceModel\RequestToken as RequestTokenResource;

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

    /** Payment additional information key listing the extra approvals already flagged for refund. */
    const FLAGGED_IDS_KEY = 'payflex_flagged_ids';

    /** @var PayflexLogger */
    private $logger;

    /** @var Communication */
    private $communication;

    /** @var OrderFinalizer */
    private $orderFinalizer;

    /** @var RequestTokenResource */
    private $requestTokenResource;

    /** @var LockManagerInterface */
    private $lockManager;

    /** @var OrderFactory */
    private $orderFactory;

    public function __construct(
        PayflexLogger $logger,
        Communication $communication,
        OrderFinalizer $orderFinalizer,
        RequestTokenResource $requestTokenResource,
        LockManagerInterface $lockManager,
        OrderFactory $orderFactory
    ) {
        $this->logger = $logger;
        $this->communication = $communication;
        $this->orderFinalizer = $orderFinalizer;
        $this->requestTokenResource = $requestTokenResource;
        $this->lockManager = $lockManager;
        $this->orderFactory = $orderFactory;
    }

    /**
     * Look the order up at Payflex and finalise it accordingly. Returns
     * RESULT_BUSY, or one of the OrderFinalizer RESULT_* constants. The order is
     * read again once the lock is held, so the $order passed in is not changed.
     *
     * @throws NotFoundException when the status could not be read from Payflex.
     */
    public function reconcile(Order $order, string $source): string
    {
        $orderIncrementId = (string) $order->getIncrementId();
        if (!$this->lockOrder($orderIncrementId)) {
            $this->logger->info(__METHOD__ . " order {$orderIncrementId} is locked by another process");
            return self::RESULT_BUSY;
        }

        try {
            $freshOrder = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
            if (!$freshOrder->getId()) {
                $this->logger->critical(__METHOD__ . " order {$orderIncrementId} could not be loaded");
                return OrderFinalizer::RESULT_NONE;
            }

            return $this->applyLiveStatus($freshOrder, $source);
        } finally {
            $this->unlockOrder($orderIncrementId);
        }
    }

    /**
     * Take the per-order lock held while an order's Payflex payment is changed,
     * waiting up to $timeout seconds. Returns false when it is held elsewhere.
     */
    public function lockOrder(string $orderIncrementId, int $timeout = 0): bool
    {
        return $this->lockManager->lock(self::LOCK_PREFIX . $orderIncrementId, $timeout);
    }

    /**
     * Release the lock taken by lockOrder().
     */
    public function unlockOrder(string $orderIncrementId): void
    {
        $this->lockManager->unlock(self::LOCK_PREFIX . $orderIncrementId);
    }

    /**
     * The Payflex order ids for a Magento order, oldest first. The id stored on
     * the payment is added at the end when it has no stored row.
     */
    public function getPayflexIds(Order $order): array
    {
        $payflexIds = $this->requestTokenResource->getPayflexIds($order->getIncrementId());

        $paymentPayflexId = $this->getPaymentPayflexId($order);
        if ($paymentPayflexId !== null && !in_array($paymentPayflexId, $payflexIds, true)) {
            $payflexIds[] = $paymentPayflexId;
        }

        return $payflexIds;
    }

    /**
     * The Payflex response that decides the order, or an empty array when it
     * could not be decided. Never throws, so it is safe for read-only callers.
     */
    public function fetchStatus(Order $order): array
    {
        $payflexIds = $this->getPayflexIds($order);
        if (empty($payflexIds)) {
            $this->logger->info(__METHOD__ . ' no Payflex order id for order ' . $order->getIncrementId());
            return [];
        }

        return $this->selectResponse($order, $this->fetchStatuses($payflexIds, $order));
    }

    /**
     * The Payflex order id stored on the payment, or null when there is none.
     */
    private function getPaymentPayflexId(Order $order)
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return null;
        }

        $info = (array) $payment->getAdditionalInformation();
        foreach (['orderId', 'payflex_id'] as $key) {
            if (!empty($info[$key])) {
                return (string) $info[$key];
            }
        }

        return null;
    }

    /**
     * Look up every Payflex order id, keyed by id. A failed lookup is an empty array.
     */
    private function fetchStatuses(array $payflexIds, Order $order): array
    {
        $responses = [];
        foreach ($payflexIds as $payflexId) {
            $responses[$payflexId] = $this->fetchStatusById($payflexId, $order);
        }

        return $responses;
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
            $this->flagLateApprovals($order);
            return OrderFinalizer::RESULT_NONE;
        }

        $payflexIds = $this->getPayflexIds($order);
        if (empty($payflexIds)) {
            $this->logger->critical(__METHOD__ . " no Payflex order id for order {$order->getIncrementId()}");
            return OrderFinalizer::RESULT_NONE;
        }

        // Throw rather than return, so that a caller that can retry knows the
        // difference between a failed lookup and an order with nothing to do.
        $responses = $this->fetchStatuses($payflexIds, $order);
        $response = $this->selectResponse($order, $responses);
        if (empty($response['orderStatus'])) {
            throw new NotFoundException(
                __('Could not read the Payflex status for order %1.', $order->getIncrementId())
            );
        }

        $this->noteExtraApprovals($order, $responses, $response);
        $this->storeStatus($order, $response['orderStatus']);

        return $this->orderFinalizer->finalizeFromStatus($order, $response, $source);
    }

    /**
     * Choose the Payflex response that decides the order: an approved one, else
     * the newest failed one when every attempt failed, else the newest open one.
     * Returns an empty array when a lookup failed and nothing was approved.
     */
    private function selectResponse(Order $order, array $responses): array
    {
        $approved = $this->approvedResponses($order, $responses);
        if (!empty($approved)) {
            return $this->pickApproved($order, $approved);
        }

        if ($this->hasFailedLookup($responses)) {
            return [];
        }

        $open = $this->openResponses($responses);
        if (!empty($open)) {
            return end($open);
        }

        return end($responses);
    }

    /**
     * The approved responses whose merchant reference matches the order, keyed
     * by Payflex order id, oldest first.
     */
    private function approvedResponses(Order $order, array $responses): array
    {
        $approved = [];
        foreach ($responses as $payflexId => $response) {
            if (empty($response['orderStatus']) || $response['orderStatus'] !== OrderFinalizer::STATUS_APPROVED) {
                continue;
            }

            if (!isset($response['merchantReference']) || $response['merchantReference'] != $order->getIncrementId()) {
                continue;
            }

            $approved[$payflexId] = $response;
        }

        return $approved;
    }

    /**
     * The first approved response whose amount matches the order, else the first one.
     */
    private function pickApproved(Order $order, array $approved): array
    {
        foreach ($approved as $response) {
            if ($this->orderFinalizer->amountMatches($order, $response)) {
                return $response;
            }
        }

        return reset($approved);
    }

    /**
     * True when at least one Payflex lookup returned no status.
     */
    private function hasFailedLookup(array $responses): bool
    {
        foreach ($responses as $response) {
            if (empty($response['orderStatus'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The responses that have not failed, oldest first.
     */
    private function openResponses(array $responses): array
    {
        $open = [];
        foreach ($responses as $response) {
            if (!in_array($response['orderStatus'], OrderFinalizer::FAILED_STATUSES, true)) {
                $open[] = $response;
            }
        }

        return $open;
    }

    /**
     * Flag the other approved payments when Payflex approved more than one.
     */
    private function noteExtraApprovals(Order $order, array $responses, array $chosen): void
    {
        $extraIds = [];
        foreach ($this->approvedResponses($order, $responses) as $payflexId => $response) {
            if ($response !== $chosen) {
                $extraIds[] = $payflexId;
            }
        }

        $this->flagExtraApprovals($order, $extraIds, $chosen['orderId']);
    }

    /**
     * Flag payments that Payflex approved after the order was already paid,
     * for example from a second browser tab. Never throws.
     */
    private function flagLateApprovals(Order $order): void
    {
        try {
            $payflexIds = $this->getPayflexIds($order);
            if (count($payflexIds) < 2) {
                return;
            }

            $paidId = $this->getPaymentPayflexId($order);
            $otherIds = array_diff($payflexIds, $this->getFlaggedIds($order), [$paidId]);
            if (empty($otherIds)) {
                return;
            }

            $approved = $this->approvedResponses($order, $this->fetchStatuses($otherIds, $order));
            $this->flagExtraApprovals($order, array_keys($approved), (string) $paidId);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . " order {$order->getIncrementId()} " . $e->getMessage());
        }
    }

    /**
     * Comment on the order and log approved payments that were not used, so
     * that they can be refunded in the Payflex portal. Each id is flagged once.
     */
    private function flagExtraApprovals(Order $order, array $extraIds, string $usedId): void
    {
        $flaggedIds = $this->getFlaggedIds($order);
        $newIds = array_values(array_diff($extraIds, $flaggedIds));
        if (empty($newIds)) {
            return;
        }

        $extraIdList = implode(', ', $newIds);
        $this->logger->critical(
            __METHOD__ . " order {$order->getIncrementId()} has more than one approved Payflex payment."
            . " Using {$usedId}, refund {$extraIdList}"
        );

        $payment = $order->getPayment();
        $payment->setAdditionalInformation(self::FLAGGED_IDS_KEY, array_merge($flaggedIds, $newIds));
        $payment->save();

        $order->addStatusHistoryComment((string) __(
            'Payflex approved more than one payment for this order. Payflex Order ID %1 was used. Please refund'
            . ' Payflex Order ID(s) %2 in the Payflex portal.',
            $usedId,
            $extraIdList
        ))->setIsCustomerNotified(false)->save();
    }

    /**
     * The Payflex order ids already flagged for refund on this order.
     */
    private function getFlaggedIds(Order $order): array
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return [];
        }

        $flaggedIds = $payment->getAdditionalInformation(self::FLAGGED_IDS_KEY);
        if (!is_array($flaggedIds)) {
            return [];
        }

        return $flaggedIds;
    }
}
