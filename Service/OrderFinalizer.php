<?php

namespace Payflex\Gateway\Service;

use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder as TransactionBuilder;
use Magento\Sales\Model\Service\InvoiceService;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Logger\PayflexLogger;

/**
 * Single place that turns a verified Payflex transaction status into a finalised
 * Magento order. Shared by the return controllers, the reconciliation cron and
 * the webhook, so the "approved -> capture + invoice" and "declined -> cancel"
 * rules only exist once.
 */
class OrderFinalizer
{
    /** Payflex status meaning the payment was taken successfully. */
    const STATUS_APPROVED = 'Approved';

    /** Payflex statuses meaning the order should be cancelled. */
    const FAILED_STATUSES = ['Declined', 'Abandoned'];

    /** The order was captured, and invoiced if it could still be invoiced. */
    const RESULT_CAPTURED = 'captured';

    /** The order was cancelled. */
    const RESULT_CANCELLED = 'cancelled';

    /** Payflex reports a paid order that Magento has already cancelled. */
    const RESULT_NEEDS_REVIEW = 'needs_review';

    /** Nothing needed doing, or Payflex has not reached a final status yet. */
    const RESULT_NONE = 'none';

    /** @var PayflexLogger */
    private $logger;

    /** @var Configuration */
    private $configuration;

    /** @var OrderSender */
    private $orderSender;

    /** @var InvoiceService */
    private $invoiceService;

    /** @var InvoiceSender */
    private $invoiceSender;

    /** @var TransactionBuilder */
    private $transactionBuilder;

    /** @var TransactionFactory */
    private $transactionFactory;

    /** @var OrderManagementInterface */
    private $orderManagement;

    public function __construct(
        PayflexLogger $logger,
        Configuration $configuration,
        OrderSender $orderSender,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        TransactionBuilder $transactionBuilder,
        TransactionFactory $transactionFactory,
        OrderManagementInterface $orderManagement
    ) {
        $this->logger = $logger;
        $this->configuration = $configuration;
        $this->orderSender = $orderSender;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->transactionBuilder = $transactionBuilder;
        $this->transactionFactory = $transactionFactory;
        $this->orderManagement = $orderManagement;
    }

    /**
     * True when the order has already reached a final state and must not be
     * captured again. Cancelled orders are not final here, so that a late
     * approval from Payflex is still noticed.
     */
    public function isFinalized(Order $order): bool
    {
        if ($order->hasInvoices()) {
            return true;
        }

        return in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED], true);
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

        if ($order->getState() === Order::STATE_CANCELED) {
            $this->logger->critical(
                __METHOD__ . " order {$order->getIncrementId()} is cancelled but Payflex reports it as paid"
            );

            $order->addStatusHistoryComment((string) __(
                '%1: Payflex reports this cancelled order as paid. Payflex Order ID: %2. The payment has been'
                . ' recorded against the order, but the order remains cancelled. Please review it and either'
                . ' refund the customer or place the order again.',
                $source,
                $payflexId
            ))->setIsCustomerNotified(false)->save();

            $this->createTransaction($order, $payflexResponse);
            $this->markProcessed($order);

            return self::RESULT_NEEDS_REVIEW;
        }

        $order->addStatusHistoryComment((string) __(
            '%1: Payflex approved the payment. Payflex Order ID: %2',
            $source,
            $payflexId
        ))->setIsCustomerNotified(false)->save();

        if (!$this->capture($order, $payflexResponse)) {
            return self::RESULT_NONE;
        }

        $this->markProcessed($order);

        return self::RESULT_CAPTURED;
    }

    /**
     * Record on the payment that the order has been processed, for the admin
     * payment information block.
     */
    private function markProcessed(Order $order): void
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }

        try {
            $payment->setAdditionalInformation('payflex_processed_at', date('Y-m-d H:i:s'));
            $payment->save();
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . ' ' . $e->getMessage());
        }
    }

    /**
     * Capture an approved order: record the capture transaction and, if the order
     * can still be invoiced, generate the invoice. Returns true when the order
     * was captured.
     */
    public function capture(Order $order, array $payflexResponse): bool
    {
        $transactionId = $this->createTransaction($order, $payflexResponse);
        if ($transactionId === false) {
            return false;
        }

        if ($order->canInvoice() && !$order->hasInvoices()) {
            $this->logger->info(__METHOD__ . ' generating invoice for order ' . $order->getIncrementId());
            $this->generateInvoice($order);
        }

        return true;
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

    /**
     * Build and persist the capture transaction for an approved Payflex order.
     *
     * Rejects anything that is not an approved response for THIS order: the
     * status must be "Approved" and the merchantReference must match the order
     * increment id (either condition failing is a rejection).
     *
     * @return string|false the transaction id on success, false when rejected
     */
    public function createTransaction(Order $order, array $paymentData)
    {
        $this->logger->info(__METHOD__);
        try {
            if ($paymentData['orderStatus'] != 'Approved'
                || $paymentData['merchantReference'] != $order->getIncrementId()) {
                $this->logger->info(__METHOD__ . ': order mismatched, not capturing');
                return false;
            }

            $payment = $order->getPayment();
            if (!$payment) {
                $this->logger->error(__METHOD__ . ': order ' . $order->getIncrementId() . ' has no payment');
                return false;
            }

            $payment->setLastTransId($paymentData['orderId'])
                ->setTransactionId($paymentData['orderId']);

            $formattedPrice = $order->getBaseCurrency()->formatTxt($order->getGrandTotal());
            $message = __('The authorized amount is %1.', $formattedPrice);

            $transaction = $this->transactionBuilder->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($paymentData['orderId'])
                ->setAdditionalInformation($paymentData)
                ->setFailSafe(true)
                ->build(Transaction::TYPE_CAPTURE);

            $payment->addTransactionCommentsToOrder($transaction, $message);
            $payment->setParentTransactionId(null);
            $payment->save();

            return $transaction->save()->getTransactionId();
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return false;
        }
    }

    /**
     * Create an online-capture invoice for the order and move it to the
     * configured paid state/status, sending order/invoice emails per config.
     */
    public function generateInvoice(Order $order): void
    {
        $storeId = $order->getStoreId();
        $state  = $this->configuration->getPayflexNewOrderState($storeId);
        $status = $this->configuration->getPayflexNewOrderStatus($storeId);

        if ($this->configuration->getOrderEmail($storeId) != '0') {
            $this->orderSender->send($order);
            $order->addStatusHistoryComment(__('Notified customer about order #%1.', $order->getId()))
                ->setIsCustomerNotified(true)
                ->save();
        }

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
        $invoice->register();

        $invoiceOrder = $invoice->getOrder();
        $invoiceOrder->setStatus($status);
        $invoiceOrder->setState($state);
        $invoiceOrder->addStatusHistoryComment(
            'Payment confirmed, Payflex Transaction ID: ' . $invoice->getTransactionId(),
            $invoiceOrder->getStatus()
        );
        $invoiceOrder->save();

        $this->transactionFactory->create()
            ->addObject($invoice)
            ->addObject($invoiceOrder)
            ->save();

        if ($this->configuration->getInvoiceEmail($storeId) != '0') {
            $this->invoiceSender->send($invoice);
            $order->addStatusHistoryComment(__('Notified customer about invoice #%1.', $invoice->getId()))
                ->setIsCustomerNotified(true)
                ->save();
        }
    }
}
