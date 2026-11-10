<?php

namespace Payflex\Gateway\Service;

use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Logger\PayflexLogger;

/**
 * Single place that turns a verified Payflex transaction status into a finalised
 * Magento order. Shared by the return controllers, the reconciliation cron and
 * the webhook, so the "approved -> capture" and "declined -> cancel" rules only
 * exist once.
 */
class OrderFinalizer
{
    /** Payflex status meaning the payment was taken successfully. */
    const STATUS_APPROVED = 'Approved';

    /** Payflex statuses meaning the order should be cancelled. */
    const FAILED_STATUSES = ['Declined', 'Abandoned'];

    /** The payment was captured and the order invoiced. */
    const RESULT_CAPTURED = 'captured';

    /** The order was cancelled. */
    const RESULT_CANCELLED = 'cancelled';

    /** The payment was recorded, but the order needs to be checked by hand. */
    const RESULT_NEEDS_REVIEW = 'needs_review';

    /** Nothing needed doing, or Payflex has not reached a final status yet. */
    const RESULT_NONE = 'none';

    /** Payment additional information key set once a Payflex payment has been recorded. */
    const PROCESSED_AT_KEY = 'payflex_processed_at';

    /** @var PayflexLogger */
    private $logger;

    /** @var Configuration */
    private $configuration;

    /** @var OrderSender */
    private $orderSender;

    /** @var InvoiceSender */
    private $invoiceSender;

    /** @var OrderManagementInterface */
    private $orderManagement;

    public function __construct(
        PayflexLogger $logger,
        Configuration $configuration,
        OrderSender $orderSender,
        InvoiceSender $invoiceSender,
        OrderManagementInterface $orderManagement
    ) {
        $this->logger = $logger;
        $this->configuration = $configuration;
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
        $this->orderManagement = $orderManagement;
    }

    /**
     * True when the order has already reached a final state or had its Payflex
     * payment recorded, and must not be captured again. Other cancelled orders
     * are not final here, so that a late approval from Payflex is still noticed.
     */
    public function isFinalized(Order $order): bool
    {
        if ($order->hasInvoices()) {
            return true;
        }

        if ($this->isPaymentRecorded($order)) {
            return true;
        }

        return in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED], true);
    }

    /**
     * True when a Payflex payment has already been recorded on the order.
     */
    private function isPaymentRecorded(Order $order): bool
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return false;
        }

        return !empty($payment->getAdditionalInformation(self::PROCESSED_AT_KEY));
    }

    /**
     * Apply a Payflex status that has already been read back from the API.
     * Returns one of the RESULT_* constants describing what was done.
     */
    public function finalizeFromStatus(Order $order, array $payflexResponse, string $source): string
    {
        $status = null;
        if (isset($payflexResponse['orderStatus'])) {
            $status = $payflexResponse['orderStatus'];
        }

        $reference = null;
        if (isset($payflexResponse['merchantReference'])) {
            $reference = $payflexResponse['merchantReference'];
        }

        $payflexId = '';
        if (isset($payflexResponse['orderId'])) {
            $payflexId = $payflexResponse['orderId'];
        }

        if ($status === self::STATUS_APPROVED && $reference == $order->getIncrementId()) {
            return $this->applyApproved($order, $payflexResponse, $source, $payflexId);
        }

        if (in_array($status, self::FAILED_STATUSES, true)) {
            $comment = (string) __(
                '%1: Payflex reports this transaction as %2. Payflex Order ID: %3',
                $source,
                strtolower($status),
                $payflexId
            );

            if ($this->cancel($order, $comment)) {
                return self::RESULT_CANCELLED;
            }

            return self::RESULT_NONE;
        }

        $this->logger->info(__METHOD__ . " order {$order->getIncrementId()} is still '{$status}', nothing to do");

        return self::RESULT_NONE;
    }

    /**
     * Handle a Payflex status of "Approved" for this order.
     */
    private function applyApproved(Order $order, array $payflexResponse, string $source, string $payflexId): string
    {
        if ($order->hasInvoices()) {
            $this->logger->info(__METHOD__ . " order {$order->getIncrementId()} is already invoiced");
            return self::RESULT_NONE;
        }

        $payment = $order->getPayment();
        if (!$payment || $payflexId === '') {
            $this->logger->error(__METHOD__ . " cannot record the payment for order {$order->getIncrementId()}");
            return self::RESULT_NONE;
        }

        if ($order->getState() === Order::STATE_CANCELED) {
            return $this->recordPaymentOnCancelledOrder($order, $payment, $payflexResponse, $source, $payflexId);
        }

        $this->preparePayment($order, $payment, $payflexResponse, $payflexId);
        $payment->registerCaptureNotification($this->approvedAmount($payflexResponse));

        $invoice = $payment->getCreatedInvoice();
        if (!$invoice) {
            $this->noteAmountMismatch($order, $payflexResponse, $source, $payflexId);
            $order->save();
            return self::RESULT_NEEDS_REVIEW;
        }

        $this->applyNewOrderStatus($order);
        $order->addStatusHistoryComment((string) __(
            '%1: Payflex approved the payment. Payflex Order ID: %2',
            $source,
            $payflexId
        ))->setIsCustomerNotified(false);
        $order->save();

        $this->sendEmails($order, $invoice);

        return self::RESULT_CAPTURED;
    }

    /**
     * Record the payment on an order that was cancelled before Payflex approved
     * it, and leave the order cancelled for review.
     */
    private function recordPaymentOnCancelledOrder(
        Order $order,
        Payment $payment,
        array $payflexResponse,
        string $source,
        string $payflexId
    ): string {
        $this->logger->critical(
            __METHOD__ . " order {$order->getIncrementId()} is cancelled but Payflex reports it as paid"
        );

        $this->preparePayment($order, $payment, $payflexResponse, $payflexId);
        $payment->addTransaction(Transaction::TYPE_CAPTURE, null, true);

        $order->addStatusHistoryComment((string) __(
            '%1: Payflex reports this cancelled order as paid. Payflex Order ID: %2. The payment has been'
            . ' recorded against the order, but the order remains cancelled. Please review it and either'
            . ' refund the customer or place the order again.',
            $source,
            $payflexId
        ))->setIsCustomerNotified(false);
        $order->save();

        return self::RESULT_NEEDS_REVIEW;
    }

    /**
     * Point the payment at the approved Payflex order so that core records the
     * capture transaction against it.
     */
    private function preparePayment(Order $order, Payment $payment, array $payflexResponse, string $payflexId): void
    {
        $payment->setTransactionId($payflexId);
        $payment->setCurrencyCode($order->getBaseCurrencyCode());
        $payment->setIsTransactionClosed(0);
        $payment->setTransactionAdditionalInfo(Transaction::RAW_DETAILS, $this->rawDetails($payflexResponse));
        $payment->setAdditionalInformation('orderId', $payflexId);
        $payment->setAdditionalInformation(self::PROCESSED_AT_KEY, date('Y-m-d H:i:s'));
    }

    /**
     * The flat values of the Payflex response, for the transaction details.
     */
    private function rawDetails(array $payflexResponse): array
    {
        $details = [];
        foreach ($payflexResponse as $key => $value) {
            if (is_scalar($value)) {
                $details[$key] = $value;
            }
        }

        return $details;
    }

    /**
     * The amount Payflex approved, or zero when the response has none.
     */
    private function approvedAmount(array $payflexResponse): float
    {
        if (!isset($payflexResponse['amount'])) {
            return 0.0;
        }

        return (float) $payflexResponse['amount'];
    }

    /**
     * True when core would invoice the amount Payflex approved, that is when it
     * equals the order's base total due.
     */
    public function amountMatches(Order $order, array $payflexResponse): bool
    {
        if (!isset($payflexResponse['amount'])) {
            return false;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            return false;
        }

        return (bool) $payment->isCaptureFinal($this->approvedAmount($payflexResponse));
    }

    /**
     * Log and comment on an approved payment whose amount differs from the order.
     */
    private function noteAmountMismatch(Order $order, array $payflexResponse, string $source, string $payflexId): void
    {
        $payflexAmount = $this->formatAmount($this->approvedAmount($payflexResponse));
        $orderAmount = $this->formatAmount($order->getBaseTotalDue());

        $this->logger->critical(
            __METHOD__ . " amount mismatch on order {$order->getIncrementId()}: Payflex approved"
            . " '{$payflexAmount}', the order base total due is '{$orderAmount}'. Payflex Order ID: {$payflexId}"
        );

        $order->addStatusHistoryComment((string) __(
            '%1: Payflex approved %2, but the order total due is %3. Payflex Order ID: %4. The order is in'
            . ' payment review and was not invoiced. Either Accept Payment and invoice the order, or Deny Payment'
            . ' and refund the payment in the Payflex portal. If Payflex approved less than the total, invoice'
            . ' offline and settle the difference, because an online invoice records the full total.',
            $source,
            $payflexAmount,
            $orderAmount,
            $payflexId
        ))->setIsCustomerNotified(false);
    }

    /**
     * An amount rounded to two decimals, for comparing and displaying.
     */
    private function formatAmount($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Move a paid order to the state and status configured for Payflex.
     */
    private function applyNewOrderStatus(Order $order): void
    {
        $storeId = $order->getStoreId();
        $order->setState($this->configuration->getPayflexNewOrderState($storeId));
        $order->setStatus($this->configuration->getPayflexNewOrderStatus($storeId));
    }

    /**
     * Send the order and invoice emails when they are enabled. A failure is
     * logged only, because the payment has already been saved.
     */
    private function sendEmails(Order $order, Invoice $invoice): void
    {
        $storeId = $order->getStoreId();

        try {
            if ($this->configuration->getOrderEmail($storeId) != '0') {
                $this->orderSender->send($order);
                $order->addStatusHistoryComment(__('Notified customer about order #%1.', $order->getId()))
                    ->setIsCustomerNotified(true)
                    ->save();
            }

            if ($this->configuration->getInvoiceEmail($storeId) != '0') {
                $this->invoiceSender->send($invoice);
                $order->addStatusHistoryComment(__('Notified customer about invoice #%1.', $invoice->getId()))
                    ->setIsCustomerNotified(true)
                    ->save();
            }
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . " order {$order->getIncrementId()} " . $e->getMessage());
        }
    }

    /**
     * Cancel an order that Payflex reports as declined/abandoned. No-op if the
     * order has already progressed past the configured "new order" status or can
     * no longer be cancelled.
     */
    public function cancel(Order $order, string $comment): bool
    {
        $processingStatus = $this->configuration->getPayflexNewOrderStatus($order->getStoreId());
        if ($order->getStatus() == $processingStatus || !$order->canCancel()) {
            return false;
        }

        $order->setEmailSent(0);
        $order->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
        $this->orderManagement->cancel($order->getId());
        $order->addStatusHistoryComment($comment);
        $order->cancel();
        $order->save();

        return true;
    }
}
