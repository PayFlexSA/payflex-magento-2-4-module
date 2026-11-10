<?php

namespace Payflex\Gateway\Test\Unit\Service;

use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as TransactionRepository;
use Magento\Sales\Model\Order\Status\History;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Service\OrderFinalizer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers finalizeFromStatus(), the decision the webhook, the cron, the admin
 * button and the customer return all share.
 */
class OrderFinalizerFinalizeTest extends TestCase
{
    /** @var Configuration&MockObject */
    private $configuration;

    /** @var OrderManagementInterface&MockObject */
    private $orderManagement;

    /** @var OrderSender&MockObject */
    private $orderSender;

    /** @var InvoiceSender&MockObject */
    private $invoiceSender;

    /** @var TransactionRepository&MockObject */
    private $transactionRepository;

    /** @var OrderFinalizer */
    private $finalizer;

    protected function setUp(): void
    {
        $this->configuration = $this->createMock(Configuration::class);
        $this->orderManagement = $this->createMock(OrderManagementInterface::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->invoiceSender = $this->createMock(InvoiceSender::class);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);

        $this->finalizer = new OrderFinalizer(
            $this->createMock(PayflexLogger::class),
            $this->configuration,
            $this->orderSender,
            $this->invoiceSender,
            $this->orderManagement,
            $this->transactionRepository
        );
    }

    public function testIsFinalizedWhenOrderHasInvoices(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('hasInvoices')->willReturn(true);

        $this->assertTrue($this->finalizer->isFinalized($order));
    }

    /**
     * A cancelled order is deliberately not "finalised", so that a later
     * approval from Payflex is still noticed instead of silently dropped.
     */
    public function testCancelledOrderIsNotTreatedAsFinalized(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('hasInvoices')->willReturn(false);
        $order->method('getState')->willReturn(Order::STATE_CANCELED);

        $this->assertFalse($this->finalizer->isFinalized($order));
    }

    /**
     * An order marked Suspected Fraud has no invoice, but its payment has been
     * recorded, so it must not be captured again.
     */
    public function testIsFinalizedOnceThePaymentIsRecorded(): void
    {
        $payment = $this->payment();
        $payment->setAdditionalInformation(OrderFinalizer::PROCESSED_AT_KEY, '2026-11-03 10:00:00');

        $order = $this->createMock(Order::class);
        $order->method('hasInvoices')->willReturn(false);
        $order->method('getState')->willReturn(Order::STATE_PAYMENT_REVIEW);
        $order->method('getPayment')->willReturn($payment);

        $this->assertTrue($this->finalizer->isFinalized($order));
    }

    /**
     * A stale payment save can remove the processed marker. A cancelled order
     * that has a capture transaction is still paid, so it stays final.
     */
    public function testCancelledOrderWithACaptureTransactionIsFinalized(): void
    {
        $payment = $this->payment();
        $payment->setId(7);
        $this->givenCaptureTransaction(7, 'pf-paid');

        $order = $this->createMock(Order::class);
        $order->method('hasInvoices')->willReturn(false);
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $order->method('getPayment')->willReturn($payment);

        $this->assertTrue($this->finalizer->isFinalized($order));
    }

    public function testCapturedPayflexIdPrefersTheCaptureTransaction(): void
    {
        $payment = $this->payment();
        $payment->setId(7);
        $payment->setAdditionalInformation('orderId', 'pf-other');
        $this->givenCaptureTransaction(7, 'pf-paid');

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->expects($this->never())->method('getInvoiceCollection');

        $this->assertSame('pf-paid', $this->finalizer->getCapturedPayflexId($order));
    }

    public function testCapturedPayflexIdFallsBackToTheInvoiceTransactionId(): void
    {
        $payment = $this->payment();
        $payment->setId(7);
        $this->transactionRepository->method('getByTransactionType')->willReturn(false);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getTransactionId')->willReturn('pf-invoiced');

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('hasInvoices')->willReturn(true);
        $order->method('getInvoiceCollection')->willReturn([$invoice]);

        $this->assertSame('pf-invoiced', $this->finalizer->getCapturedPayflexId($order));
    }

    public function testCapturedPayflexIdIsNullWithoutACaptureOrAnInvoice(): void
    {
        $payment = $this->payment();
        $payment->setId(7);
        $payment->setAdditionalInformation('orderId', 'pf-other');
        $this->transactionRepository->method('getByTransactionType')->willReturn(false);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('hasInvoices')->willReturn(false);

        $this->assertNull($this->finalizer->getCapturedPayflexId($order));
    }

    public function testApprovedOrderWithMismatchedReferenceIsNotCaptured(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('hasInvoices')->willReturn(false);
        $order->expects($this->never())->method('getPayment');

        $result = $this->finalizer->finalizeFromStatus($order, [
            'orderStatus'       => 'Approved',
            'merchantReference' => 'SOMEONE-ELSE',
            'orderId'           => 'pf-1',
        ], 'Payflex webhook');

        $this->assertSame(OrderFinalizer::RESULT_NONE, $result);
    }

    public function testApprovedOrderThatIsAlreadyInvoicedIsLeftAlone(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('hasInvoices')->willReturn(true);
        $order->expects($this->never())->method('addStatusHistoryComment');
        $order->expects($this->never())->method('getPayment');

        $result = $this->finalizer->finalizeFromStatus($order, [
            'orderStatus'       => 'Approved',
            'merchantReference' => '100000001',
            'orderId'           => 'pf-1',
        ], 'Payflex webhook');

        $this->assertSame(OrderFinalizer::RESULT_NONE, $result);
    }

    /**
     * Payflex says a cancelled order was paid. The payment is recorded and the
     * order is flagged, but it is not invoiced or reopened.
     */
    public function testApprovedButCancelledOrderIsFlaggedForReview(): void
    {
        $payment = $this->payment();
        $payment->expects($this->once())->method('addTransaction')
            ->with(Transaction::TYPE_CAPTURE, null, true);
        $payment->expects($this->never())->method('registerCaptureNotification');

        $order = $this->order($payment, Order::STATE_CANCELED);
        $order->expects($this->once())->method('addStatusHistoryComment')->willReturn($this->history());
        $order->expects($this->once())->method('save');
        $order->expects($this->never())->method('setState');

        $result = $this->finalizer->finalizeFromStatus($order, $this->approved(150), 'Payflex webhook');

        $this->assertSame(OrderFinalizer::RESULT_NEEDS_REVIEW, $result);
        $this->assertSame('pf-1', $payment->getTransactionId());
        $this->assertSame('pf-1', $payment->getAdditionalInformation('orderId'));
    }

    /**
     * Core creates and pays the invoice, then the configured status is applied
     * and the emails are sent.
     */
    public function testApprovedWithTheMatchingAmountIsCaptured(): void
    {
        $this->configuration->method('getPayflexNewOrderState')->willReturn('processing');
        $this->configuration->method('getPayflexNewOrderStatus')->willReturn('payflex_paid');
        $this->configuration->method('getOrderEmail')->willReturn('1');
        $this->configuration->method('getInvoiceEmail')->willReturn('1');

        $invoice = $this->createMock(Invoice::class);
        $payment = $this->payment();
        $payment->expects($this->once())->method('registerCaptureNotification')->with(150.0)
            ->willReturnCallback(function () use ($payment, $invoice) {
                $payment->setCreatedInvoice($invoice);
                return $payment;
            });

        $order = $this->order($payment, Order::STATE_PENDING_PAYMENT);
        $order->method('addStatusHistoryComment')->willReturn($this->history());
        $order->expects($this->once())->method('setState')->with('processing')->willReturnSelf();
        $order->expects($this->once())->method('setStatus')->with('payflex_paid')->willReturnSelf();
        $order->expects($this->once())->method('save');

        $this->orderSender->expects($this->once())->method('send')->with($order);
        $this->invoiceSender->expects($this->once())->method('send')->with($invoice);

        $result = $this->finalizer->finalizeFromStatus($order, $this->approved(150), 'Payflex webhook');

        $this->assertSame(OrderFinalizer::RESULT_CAPTURED, $result);
        $this->assertSame('pf-1', $payment->getTransactionId());
        $this->assertSame('ZAR', $payment->getCurrencyCode());
        $this->assertSame('pf-1', $payment->getAdditionalInformation('orderId'));
        $this->assertNotEmpty($payment->getAdditionalInformation(OrderFinalizer::PROCESSED_AT_KEY));
    }

    /**
     * Core creates no invoice and marks the order Suspected Fraud. That status
     * is kept, and the amounts are noted on the order.
     */
    public function testApprovedWithTheWrongAmountIsLeftForReview(): void
    {
        $payment = $this->payment();
        $payment->expects($this->once())->method('registerCaptureNotification')->with(100.0)->willReturnSelf();

        $order = $this->order($payment, Order::STATE_PENDING_PAYMENT);
        $order->expects($this->once())->method('addStatusHistoryComment')
            ->with($this->logicalAnd(
                $this->stringContains('100.00'),
                $this->stringContains('150.00'),
                $this->stringContains('pf-1'),
                $this->stringContains('Accept Payment'),
                $this->stringContains('Deny Payment')
            ))
            ->willReturn($this->history());
        $order->expects($this->never())->method('setState');
        $order->expects($this->never())->method('setStatus');
        $order->expects($this->once())->method('save');

        $this->orderSender->expects($this->never())->method('send');
        $this->invoiceSender->expects($this->never())->method('send');

        $result = $this->finalizer->finalizeFromStatus($order, $this->approved(100), 'Payflex webhook');

        $this->assertSame(OrderFinalizer::RESULT_NEEDS_REVIEW, $result);
        $this->assertSame('pf-1', $payment->getAdditionalInformation('orderId'));
    }

    /**
     * The amount check is the one core uses to decide whether to invoice.
     */
    public function testAmountMatchesAsksCoreWhetherTheCaptureIsFinal(): void
    {
        $payment = $this->payment();
        $payment->expects($this->exactly(2))->method('isCaptureFinal')
            ->willReturnCallback(function ($amount) {
                return $amount === 27.0;
            });

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);

        $this->assertTrue($this->finalizer->amountMatches($order, ['amount' => '27.00']));
        $this->assertFalse($this->finalizer->amountMatches($order, ['amount' => 27.01]));
        $this->assertFalse($this->finalizer->amountMatches($order, []));
    }

    public function testAmountDoesNotMatchWithoutAPayment(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn(null);

        $this->assertFalse($this->finalizer->amountMatches($order, ['amount' => 27]));
    }

    public function testDeclinedOrderIsCancelled(): void
    {
        $this->configuration->method('getPayflexNewOrderStatus')->willReturn('processing');

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getStatus')->willReturn('pending_payment');
        $order->method('canCancel')->willReturn(true);
        $order->method('getId')->willReturn(55);
        $order->method('setStatus')->willReturnSelf();
        $order->method('setState')->willReturnSelf();
        $order->method('addStatusHistoryComment')->willReturnSelf();

        $this->orderManagement->expects($this->once())->method('cancel')->with(55);

        $result = $this->finalizer->finalizeFromStatus($order, [
            'orderStatus'       => 'Declined',
            'merchantReference' => '100000001',
            'orderId'           => 'pf-1',
        ], 'Payflex CRON');

        $this->assertSame(OrderFinalizer::RESULT_CANCELLED, $result);
    }

    public function testAbandonedOrderThatCannotBeCancelledReportsNoAction(): void
    {
        $this->configuration->method('getPayflexNewOrderStatus')->willReturn('processing');

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getStatus')->willReturn('pending_payment');
        $order->method('canCancel')->willReturn(false);

        $this->orderManagement->expects($this->never())->method('cancel');

        $result = $this->finalizer->finalizeFromStatus($order, [
            'orderStatus'       => 'Abandoned',
            'merchantReference' => '100000001',
            'orderId'           => 'pf-1',
        ], 'Payflex CRON');

        $this->assertSame(OrderFinalizer::RESULT_NONE, $result);
    }

    /**
     * @dataProvider nonFinalStatuses
     */
    public function testNonFinalStatusesDoNothing(string $status): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->expects($this->never())->method('addStatusHistoryComment');
        $this->orderManagement->expects($this->never())->method('cancel');

        $result = $this->finalizer->finalizeFromStatus($order, [
            'orderStatus'       => $status,
            'merchantReference' => '100000001',
            'orderId'           => 'pf-1',
        ], 'Payflex CRON');

        $this->assertSame(OrderFinalizer::RESULT_NONE, $result);
    }

    public function nonFinalStatuses(): array
    {
        return [
            'created'   => ['Created'],
            'initiated' => ['Initiated'],
        ];
    }

    /**
     * A payment that keeps its data, with the core calls stubbed out.
     */
    private function givenCaptureTransaction(int $paymentId, string $txnId): void
    {
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('getTxnId')->willReturn($txnId);
        $this->transactionRepository->method('getByTransactionType')
            ->with(Transaction::TYPE_CAPTURE, $paymentId)
            ->willReturn($transaction);
    }

    private function payment(): Payment
    {
        return $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['registerCaptureNotification', 'addTransaction', 'isCaptureFinal', 'save'])
            ->getMock();
    }

    private function order(Payment $payment, string $state): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('hasInvoices')->willReturn(false);
        $order->method('getState')->willReturn($state);
        $order->method('getBaseTotalDue')->willReturn(150.0);
        $order->method('getBaseCurrencyCode')->willReturn('ZAR');
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    private function history(): History
    {
        $history = $this->createMock(History::class);
        $history->method('setIsCustomerNotified')->willReturnSelf();

        return $history;
    }

    private function approved($amount): array
    {
        return [
            'orderStatus'       => 'Approved',
            'merchantReference' => '100000001',
            'orderId'           => 'pf-1',
            'amount'            => $amount,
        ];
    }
}
