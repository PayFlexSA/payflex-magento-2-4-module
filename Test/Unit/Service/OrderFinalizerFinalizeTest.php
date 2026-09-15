<?php

namespace Payflex\Gateway\Test\Unit\Service;

use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction\Builder as TransactionBuilder;
use Magento\Sales\Model\Service\InvoiceService;
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

    /** @var TransactionBuilder&MockObject */
    private $transactionBuilder;

    /** @var OrderFinalizer */
    private $finalizer;

    protected function setUp(): void
    {
        $this->configuration = $this->createMock(Configuration::class);
        $this->orderManagement = $this->createMock(OrderManagementInterface::class);
        $this->transactionBuilder = $this->createMock(TransactionBuilder::class);

        $this->finalizer = new OrderFinalizer(
            $this->createMock(PayflexLogger::class),
            $this->configuration,
            $this->createMock(OrderSender::class),
            $this->createMock(InvoiceService::class),
            $this->createMock(InvoiceSender::class),
            $this->transactionBuilder,
            $this->createMock(TransactionFactory::class),
            $this->orderManagement
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
     * order is flagged, but it is not silently reopened.
     */
    public function testApprovedButCancelledOrderIsFlaggedForReview(): void
    {
        $history = $this->createMock(\Magento\Sales\Model\Order\Status\History::class);
        $history->method('setIsCustomerNotified')->willReturnSelf();

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('hasInvoices')->willReturn(false);
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $order->method('getPayment')->willReturn(null);
        $order->expects($this->once())->method('addStatusHistoryComment')->willReturn($history);

        // createTransaction() bails out without a payment, which is enough here:
        // the order must not be invoiced or reopened.
        $order->expects($this->never())->method('canInvoice');

        $result = $this->finalizer->finalizeFromStatus($order, [
            'orderStatus'       => 'Approved',
            'merchantReference' => '100000001',
            'orderId'           => 'pf-1',
        ], 'Payflex webhook');

        $this->assertSame(OrderFinalizer::RESULT_NEEDS_REVIEW, $result);
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
}
