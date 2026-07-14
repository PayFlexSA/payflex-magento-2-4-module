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
use Magento\Store\Model\StoreManagerInterface;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Logger\PayflexLogger;

/**
 * Single place that turns a verified Payflex transaction status into a finalised
 * Magento order. Shared by the return controllers, the reconciliation cron and
 * any future webhook, so the "approved -> capture + invoice" and
 * "declined -> cancel" rules only exist once.
 */
class OrderFinalizer
{
    /** @var PayflexLogger */
    private $logger;

    /** @var Configuration */
    private $configuration;

    /** @var StoreManagerInterface */
    private $storeManager;

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
        StoreManagerInterface $storeManager,
        OrderSender $orderSender,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        TransactionBuilder $transactionBuilder,
        TransactionFactory $transactionFactory,
        OrderManagementInterface $orderManagement
    ) {
        $this->logger = $logger;
        $this->configuration = $configuration;
        $this->storeManager = $storeManager;
        $this->orderSender = $orderSender;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->transactionBuilder = $transactionBuilder;
        $this->transactionFactory = $transactionFactory;
        $this->orderManagement = $orderManagement;
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
        $processingStatus = $this->configuration->getPayflexNewOrderStatus($this->storeManager->getStore()->getId());
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
        $storeId = $this->storeManager->getStore()->getId();
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
