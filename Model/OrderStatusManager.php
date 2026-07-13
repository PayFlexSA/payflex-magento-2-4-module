<?php
/**
 * Reconciles a Magento order against its live Payflex status.
 *
 * This is the manual counterpart to the status cron: it can look up the live
 * status from Payflex for a single order and, when they disagree, apply the
 * same corrective action the cron performs automatically (invoice an approved
 * order, or cancel a declined/abandoned one).
 *
 * Kept deliberately thin — the heavy lifting (transaction + invoice creation,
 * cancellation) is reused from {@see \Payflex\Gateway\Helper\PaymentUtil}.
 */

namespace Payflex\Gateway\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Helper\PaymentUtil;
use Payflex\Gateway\Logger\PayflexLogger;

class OrderStatusManager
{
    /** Payflex statuses that mean a successful payment. */
    private const STATUS_APPROVED = 'Approved';

    /** Payflex statuses that mean the order should be cancelled. */
    private const FAILED_STATUSES = ['Declined', 'Abandoned'];

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var Communication
     */
    private $communication;

    /**
     * @var PaymentUtil
     */
    private $paymentUtil;

    /**
     * @var RequestTokenFactory
     */
    private $requestTokenFactory;

    /**
     * @var PayflexLogger
     */
    private $logger;

    /**
     * @var TimezoneInterface
     */
    private $localeDate;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        Communication $communication,
        PaymentUtil $paymentUtil,
        RequestTokenFactory $requestTokenFactory,
        PayflexLogger $logger,
        TimezoneInterface $localeDate
    ) {
        $this->orderRepository = $orderRepository;
        $this->communication = $communication;
        $this->paymentUtil = $paymentUtil;
        $this->requestTokenFactory = $requestTokenFactory;
        $this->logger = $logger;
        $this->localeDate = $localeDate;
    }

    /**
     * Look up the live Payflex status for an order and report whether it is in
     * sync with the Magento order. Also stores the freshly fetched status on the
     * payment so the admin block stays current.
     *
     * @param int $orderEntityId
     * @return array
     */
    public function checkStatus($orderEntityId)
    {
        $this->logger->info(__METHOD__ . " orderEntityId:{$orderEntityId}");

        try {
            $order = $this->orderRepository->get((int) $orderEntityId);
        } catch (\Exception $e) {
            return $this->error(__('Order could not be loaded.'));
        }

        $live = $this->fetchLiveStatus($order);
        if ($live === null) {
            return $this->error(__('Could not retrieve the status from Payflex.'));
        }

        $checkedAt = $this->storeStatus($order, $live);

        $action = $this->resolveAction($order, $live);

        return [
            'success'            => true,
            'payflex_status'     => $live,
            'order_status'       => $order->getStatus(),
            'order_status_label' => $this->statusLabel($order),
            'checked_at'         => $this->formatCheckedAt($checkedAt),
            'mismatch'           => $action !== 'none',
            'action'             => $action,
            'message'            => $action === 'none'
                ? (string) __('The order is in sync with Payflex.')
                : (string) __('The Payflex status differs from the order. You can update the order to match.'),
        ];
    }

    /**
     * Apply the live Payflex status to the Magento order, mirroring the cron:
     * invoice an approved order, or cancel a declined/abandoned one.
     *
     * @param int $orderEntityId
     * @return array
     */
    public function applyStatus($orderEntityId)
    {
        $this->logger->info(__METHOD__ . " orderEntityId:{$orderEntityId}");

        try {
            $order = $this->orderRepository->get((int) $orderEntityId);
        } catch (\Exception $e) {
            return $this->error(__('Order could not be loaded.'));
        }

        $payflexId = $this->getPayflexId($order);
        $response = $this->fetchResponse($order);
        $live = isset($response['orderStatus']) ? $response['orderStatus'] : null;

        if ($live === null) {
            return $this->error(__('Could not retrieve the status from Payflex.'));
        }

        $action = $this->resolveAction($order, $live);

        try {
            if ($action === 'invoice') {
                $this->paymentUtil->createTransaction($order, $response);
                if ($order->canInvoice() && !$order->hasInvoices()) {
                    $this->paymentUtil->generateInvoice($order);
                }
                $order->addCommentToStatusHistory(
                    __('Payflex: order manually reconciled to "%1" by admin. Payflex Order ID: %2', $live, $payflexId)
                );
                $message = __('Payment confirmed and the order has been invoiced.');
            } elseif ($action === 'cancel') {
                $this->paymentUtil->orderManagement->cancel($order->getId());
                $order->addCommentToStatusHistory(
                    __('Payflex: order manually cancelled by admin (%1). Payflex Order ID: %2', $live, $payflexId)
                );
                $message = __('The order has been cancelled.');
            } else {
                $message = __('No action was required; the order is already in sync.');
            }
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' ' . $e->getMessage());
            return $this->error(__('The order could not be updated: %1', $e->getMessage()));
        }

        $checkedAt = $this->storeStatus($order, $live, $action === 'invoice');

        // Reload to report the post-update status.
        $order = $this->orderRepository->get((int) $orderEntityId);

        return [
            'success'            => true,
            'payflex_status'     => $live,
            'order_status'       => $order->getStatus(),
            'order_status_label' => $this->statusLabel($order),
            'checked_at'         => $this->formatCheckedAt($checkedAt),
            'mismatch'           => false,
            'action'             => 'none',
            'message'            => (string) $message,
        ];
    }

    /**
     * Decide what corrective action (if any) is available for a live status.
     *
     * @param OrderInterface $order
     * @param string $live
     * @return string One of 'invoice', 'cancel', 'none'.
     */
    private function resolveAction(OrderInterface $order, $live)
    {
        if ($live === self::STATUS_APPROVED) {
            return $order->canInvoice() ? 'invoice' : 'none';
        }

        if (in_array($live, self::FAILED_STATUSES, true)) {
            return $order->canCancel() ? 'cancel' : 'none';
        }

        return 'none';
    }

    /**
     * Live status string from the Payflex API, or null on failure.
     *
     * @param OrderInterface $order
     * @return string|null
     */
    private function fetchLiveStatus(OrderInterface $order)
    {
        $response = $this->fetchResponse($order);

        return isset($response['orderStatus']) ? $response['orderStatus'] : null;
    }

    /**
     * Raw decoded Payflex order response, or an empty array on failure.
     *
     * @param OrderInterface $order
     * @return array
     */
    private function fetchResponse(OrderInterface $order)
    {
        $payflexId = $this->getPayflexId($order);
        if ($payflexId === null) {
            $this->logger->info(__METHOD__ . ' no Payflex order id for order ' . $order->getIncrementId());
            return [];
        }

        try {
            $response = $this->communication->getTransactionStatus($payflexId, $order->getStoreId());
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' ' . $e->getMessage());
            return [];
        }

        return is_array($response) ? $response : [];
    }

    /**
     * Resolve the Payflex order id for a Magento order from the payment
     * additional information, falling back to the RequestToken record.
     *
     * @param OrderInterface $order
     * @return string|null
     */
    private function getPayflexId(OrderInterface $order)
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
     * Persist the latest Payflex status (and optionally a processed timestamp)
     * on the order payment.
     *
     * @param OrderInterface $order
     * @param string $status
     * @param bool $markProcessed
     * @return string The raw "checked at" timestamp that was stored (Y-m-d H:i:s).
     */
    private function storeStatus(OrderInterface $order, $status, $markProcessed = false)
    {
        $now = date('Y-m-d H:i:s');

        $payment = $order->getPayment();
        if (!$payment) {
            return $now;
        }

        $payment->setAdditionalInformation('orderStatus', $status);
        $payment->setAdditionalInformation('payflex_status_checked_at', $now);
        if ($markProcessed) {
            $payment->setAdditionalInformation('payflex_processed_at', $now);
        }

        try {
            $this->orderRepository->save($order);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' ' . $e->getMessage());
        }

        return $now;
    }

    /**
     * Format a raw "checked at" timestamp for display, matching the admin block
     * ("M j, Y g:i A" in the admin timezone). Falls back to the raw value.
     *
     * @param string $raw
     * @return string
     */
    private function formatCheckedAt($raw)
    {
        try {
            return $this->localeDate->date($raw)->format('M j, Y g:i A');
        } catch (\Exception $e) {
            return (string) $raw;
        }
    }

    /**
     * @param OrderInterface $order
     * @return string
     */
    private function statusLabel(OrderInterface $order)
    {
        // getStatusLabel() exists on the order model across all supported versions.
        return method_exists($order, 'getStatusLabel')
            ? (string) $order->getStatusLabel()
            : (string) $order->getStatus();
    }

    /**
     * @param \Magento\Framework\Phrase|string $message
     * @return array
     */
    private function error($message)
    {
        return ['success' => false, 'message' => (string) $message];
    }
}
