<?php
/**
 * Admin Check Status / Update Status actions for a single order.
 */

namespace Payflex\Gateway\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
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
     * @var OrderFinalizer
     */
    private $orderFinalizer;

    /**
     * @var PayflexLogger
     */
    private $logger;

    /**
     * @var TimezoneInterface
     */
    private $localeDate;

    /**
     * @var OrderFactory
     */
    private $orderFactory;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderReconciler $orderReconciler,
        OrderFinalizer $orderFinalizer,
        PayflexLogger $logger,
        TimezoneInterface $localeDate,
        OrderFactory $orderFactory
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderReconciler = $orderReconciler;
        $this->orderFinalizer = $orderFinalizer;
        $this->logger = $logger;
        $this->localeDate = $localeDate;
        $this->orderFactory = $orderFactory;
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
        $action = $this->resolveAction($order, $response);

        return [
            'success'            => true,
            'payflex_status'     => $live,
            'order_status'       => $order->getStatus(),
            'order_status_label' => $this->statusLabel($order),
            'checked_at'         => $this->formatCheckedAt($checkedAt),
            'mismatch'           => $action !== 'none',
            'action'             => $action,
            'message'            => (string) $this->checkMessage($action),
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

        // The repository returns its cached copy, so load the order again.
        $order = $this->orderFactory->create()->loadByIncrementId($order->getIncrementId());

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
     * @param array $response
     * @return string One of 'invoice', 'fraud', 'review', 'cancel', 'none'.
     */
    private function resolveAction(OrderInterface $order, array $response)
    {
        $live = $response['orderStatus'];
        if ($live === OrderFinalizer::STATUS_APPROVED) {
            return $this->resolveApprovedAction($order, $response);
        }

        if (in_array($live, OrderFinalizer::FAILED_STATUSES, true)) {
            return $order->canCancel() ? 'cancel' : 'none';
        }

        return 'none';
    }

    /**
     * What Update Status would do with an approved payment: invoice the order,
     * mark it Suspected Fraud when the amount differs, or record the payment
     * on a cancelled order for review.
     *
     * @param OrderInterface $order
     * @param array $response
     * @return string
     */
    private function resolveApprovedAction(OrderInterface $order, array $response)
    {
        if ($this->orderFinalizer->isFinalized($order)) {
            return 'none';
        }

        if ($order->getState() === Order::STATE_CANCELED) {
            return 'review';
        }

        if (!$order->canInvoice()) {
            return 'none';
        }

        if (!$this->orderFinalizer->amountMatches($order, $response)) {
            return 'fraud';
        }

        return 'invoice';
    }

    /**
     * The admin message describing the action Check Status found.
     *
     * @param string $action
     * @return \Magento\Framework\Phrase
     */
    private function checkMessage($action)
    {
        if ($action === 'none') {
            return __('The order is in sync with Payflex.');
        }

        if ($action === 'fraud') {
            return __(
                'Payflex approved a different amount than the order total. Updating will record the payment'
                . ' and mark the order as Suspected Fraud for review.'
            );
        }

        if ($action === 'review') {
            return __(
                'Payflex reports this cancelled order as paid. Updating will record the payment for review.'
            );
        }

        return __('The Payflex status differs from the order. You can update the order to match.');
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
            return __('The Payflex payment was recorded, but the order needs to be reviewed. Please read the order comments.');
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
