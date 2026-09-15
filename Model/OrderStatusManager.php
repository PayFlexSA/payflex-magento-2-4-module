<?php
/**
 * Reconciles a Magento order against its live Payflex status.
 *
 * This is the manual counterpart to the status cron and the webhook: it can look
 * up the live status from Payflex for a single order and, when they disagree,
 * apply the same corrective action those do automatically.
 *
 * Kept deliberately thin - the work is reused from
 * {@see \Payflex\Gateway\Service\OrderReconciler}.
 */

namespace Payflex\Gateway\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Service\OrderFinalizer;
use Payflex\Gateway\Service\OrderReconciler;

class OrderStatusManager
{
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var OrderReconciler
     */
    private $orderReconciler;

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
        OrderReconciler $orderReconciler,
        PayflexLogger $logger,
        TimezoneInterface $localeDate
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderReconciler = $orderReconciler;
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

        $response = $this->orderReconciler->fetchStatus($order);
        if (empty($response['orderStatus'])) {
            return $this->error(__('Could not retrieve the status from Payflex.'));
        }

        $live = $response['orderStatus'];
        $checkedAt = $this->orderReconciler->storeStatus($order, $live);
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
     * Apply the live Payflex status to the Magento order, mirroring the cron and
     * the webhook.
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

        try {
            $action = $this->orderReconciler->reconcile($order, 'Payflex admin');
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' ' . $e->getMessage());
            return $this->error(__('Could not retrieve the status from Payflex.'));
        }

        if ($action === OrderReconciler::RESULT_BUSY) {
            return $this->error(__('This order is already being updated. Please try again in a moment.'));
        }

        // Reload to report the post-update status.
        $order = $this->orderRepository->get((int) $orderEntityId);

        return [
            'success'            => true,
            'payflex_status'     => $this->storedValue($order, 'orderStatus'),
            'order_status'       => $order->getStatus(),
            'order_status_label' => $this->statusLabel($order),
            'checked_at'         => $this->formatCheckedAt($this->storedValue($order, 'payflex_status_checked_at')),
            'mismatch'           => false,
            'action'             => 'none',
            'message'            => (string) $this->applyMessage($action),
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
        if ($live === OrderFinalizer::STATUS_APPROVED) {
            return $order->canInvoice() ? 'invoice' : 'none';
        }

        if (in_array($live, OrderFinalizer::FAILED_STATUSES, true)) {
            return $order->canCancel() ? 'cancel' : 'none';
        }

        return 'none';
    }

    /**
     * The admin message describing what reconciling the order did.
     *
     * @param string $action
     * @return \Magento\Framework\Phrase
     */
    private function applyMessage($action)
    {
        if ($action === OrderFinalizer::RESULT_CAPTURED) {
            return __('Payment confirmed and the order has been invoiced.');
        }

        if ($action === OrderFinalizer::RESULT_CANCELLED) {
            return __('The order has been cancelled.');
        }

        if ($action === OrderFinalizer::RESULT_NEEDS_REVIEW) {
            return __(
                'Payflex reports this cancelled order as paid. The payment has been recorded against the'
                . ' order, but the order is still cancelled. Please read the order comments.'
            );
        }

        return __('No action was required; the order is already in sync.');
    }

    /**
     * Read a value that was stored on the payment, or null when it is not set.
     *
     * @param OrderInterface $order
     * @param string $key
     * @return string|null
     */
    private function storedValue(OrderInterface $order, $key)
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return null;
        }

        $info = (array) $payment->getAdditionalInformation();
        if (empty($info[$key])) {
            return null;
        }

        return (string) $info[$key];
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
        if (!$raw) {
            return '';
        }

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
