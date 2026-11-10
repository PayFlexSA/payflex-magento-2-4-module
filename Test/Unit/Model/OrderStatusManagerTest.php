<?php

namespace Payflex\Gateway\Test\Unit\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\OrderStatusManager;
use Payflex\Gateway\Service\OrderFinalizer;
use Payflex\Gateway\Service\OrderReconciler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the admin Check Status and Update Status actions. Check Status is a
 * read only, and only Update Status changes the order.
 */
class OrderStatusManagerTest extends TestCase
{
    /** @var OrderRepositoryInterface&MockObject */
    private $orderRepository;

    /** @var OrderReconciler&MockObject */
    private $orderReconciler;

    /** @var OrderFinalizer&MockObject */
    private $orderFinalizer;

    /** @var TimezoneInterface&MockObject */
    private $localeDate;

    /** @var OrderFactory&MockObject */
    private $orderFactory;

    /** @var OrderStatusManager */
    private $manager;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderReconciler = $this->createMock(OrderReconciler::class);
        $this->orderFinalizer = $this->createMock(OrderFinalizer::class);
        $this->localeDate = $this->createMock(TimezoneInterface::class);
        $this->localeDate->method('date')->willReturnCallback(function ($date) {
            return new \DateTime($date);
        });
        $this->orderFactory = $this->createMock(OrderFactory::class);

        $this->manager = new OrderStatusManager(
            $this->orderRepository,
            $this->orderReconciler,
            $this->orderFinalizer,
            $this->createMock(PayflexLogger::class),
            $this->localeDate,
            $this->orderFactory
        );
    }

    /**
     * A pending order that Payflex approved is reported as needing an invoice,
     * but Check Status saves nothing and does not reconcile.
     */
    public function testCheckStatusReportsAnInvoiceWithoutSavingAnything(): void
    {
        $staleOrder = $this->order();
        $freshOrder = $this->order();
        $this->givenOrders($staleOrder, $freshOrder);
        $this->orderReconciler->method('fetchStatus')->with($staleOrder)->willReturn($this->response('Approved'));
        $this->orderReconciler->expects($this->never())->method('reconcile');
        $this->orderFinalizer->method('isFinalized')->willReturn(false);
        $this->orderFinalizer->method('amountMatches')->willReturn(true);
        $freshOrder->method('canInvoice')->willReturn(true);

        $result = $this->manager->checkStatus(5);

        $this->assertTrue($result['success']);
        $this->assertSame('invoice', $result['action']);
        $this->assertTrue($result['mismatch']);
    }

    /**
     * The webhook captured the order while Check Status was reading Payflex.
     * The comparison uses the order as it is after the lookups, so no false
     * mismatch is reported, and the stale copy is never saved.
     */
    public function testCheckStatusComparesWithTheOrderAsItIsAfterTheLookups(): void
    {
        $staleOrder = $this->order();
        $freshOrder = $this->order();
        $this->givenOrders($staleOrder, $freshOrder);
        $this->orderReconciler->method('fetchStatus')->willReturn($this->response('Approved'));
        $this->orderFinalizer->method('isFinalized')->willReturnCallback(function ($order) use ($freshOrder) {
            return $order === $freshOrder;
        });
        $staleOrder->method('canInvoice')->willReturn(true);
        $this->orderFinalizer->method('amountMatches')->willReturn(true);

        $result = $this->manager->checkStatus(5);

        $this->assertTrue($result['success']);
        $this->assertSame('none', $result['action']);
        $this->assertFalse($result['mismatch']);
    }

    public function testCheckStatusReportsACancelWhenEveryAttemptFailed(): void
    {
        $freshOrder = $this->order();
        $this->givenOrders($this->order(), $freshOrder);
        $this->orderReconciler->method('fetchStatus')->willReturn($this->response('Abandoned'));
        $this->orderReconciler->expects($this->never())->method('reconcile');
        $freshOrder->method('canCancel')->willReturn(true);

        $result = $this->manager->checkStatus(5);

        $this->assertSame('cancel', $result['action']);
        $this->assertSame('Abandoned', $result['payflex_status']);
    }

    public function testCheckStatusReturnsTheCheckTimeWithoutStoringIt(): void
    {
        $this->givenOrders($this->order(), $this->order());
        $this->orderReconciler->method('fetchStatus')->willReturn($this->response('Created'));
        $result = $this->manager->checkStatus(5);

        $this->assertNotSame('', $result['checked_at']);
        $this->assertNotFalse(\DateTime::createFromFormat('M j, Y g:i A', $result['checked_at']));
        $this->assertSame('none', $result['action']);
    }

    public function testCheckStatusReportsAnErrorWhenPayflexCannotBeRead(): void
    {
        $this->orderRepository->method('get')->willReturn($this->order());
        $this->orderReconciler->method('fetchStatus')->willReturn([]);
        $this->orderFactory->expects($this->never())->method('create');

        $result = $this->manager->checkStatus(5);

        $this->assertFalse($result['success']);
    }

    public function testApplyStatusReportsBusyWhenTheOrderIsLocked(): void
    {
        $this->orderRepository->method('get')->willReturn($this->order());
        $this->orderReconciler->method('reconcile')->willReturn(OrderReconciler::RESULT_BUSY);

        $result = $this->manager->applyStatus(5);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already being updated', $result['message']);
    }

    /**
     * Check Status invoices an approved pending order through the locked
     * Update path, which decides again from its own read.
     */
    public function testCheckAndApplyInvoicesThroughTheLockedUpdatePath(): void
    {
        $freshOrder = $this->order();
        $this->givenOrders($this->order(), $freshOrder);
        $this->orderReconciler->method('fetchStatus')->willReturn($this->response('Approved'));
        $this->orderFinalizer->method('isFinalized')->willReturn(false);
        $this->orderFinalizer->method('amountMatches')->willReturn(true);
        $freshOrder->method('canInvoice')->willReturn(true);
        $this->orderReconciler->expects($this->once())
            ->method('reconcile')
            ->with($this->anything(), 'Payflex admin')
            ->willReturn(OrderFinalizer::RESULT_CAPTURED);

        $result = $this->manager->checkAndApply(5);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['updated']);
        $this->assertFalse($result['mismatch']);
        $this->assertSame('Payment confirmed and the order has been invoiced.', $result['message']);
    }

    public function testCheckAndApplyCancelsWhenEveryAttemptFailed(): void
    {
        $freshOrder = $this->order();
        $this->givenOrders($this->order(), $freshOrder);
        $this->orderReconciler->method('fetchStatus')->willReturn($this->response('Abandoned'));
        $freshOrder->method('canCancel')->willReturn(true);
        $this->orderReconciler->expects($this->once())
            ->method('reconcile')
            ->willReturn(OrderFinalizer::RESULT_CANCELLED);

        $result = $this->manager->checkAndApply(5);

        $this->assertTrue($result['updated']);
        $this->assertSame('The order has been cancelled.', $result['message']);
    }

    /**
     * A wrong amount or a paid, cancelled order needs a person to look at it,
     * so Check Status only reports it.
     *
     * @dataProvider reviewCases
     */
    public function testCheckAndApplyOnlyReportsPaymentsThatNeedReview(string $state, bool $amountMatches, string $action): void
    {
        $freshOrder = $this->order();
        $freshOrder->method('getState')->willReturn($state);
        $freshOrder->method('canInvoice')->willReturn(true);
        $this->givenOrders($this->order(), $freshOrder);
        $this->orderReconciler->method('fetchStatus')->willReturn($this->response('Approved'));
        $this->orderFinalizer->method('isFinalized')->willReturn(false);
        $this->orderFinalizer->method('amountMatches')->willReturn($amountMatches);
        $this->orderReconciler->expects($this->never())->method('reconcile');

        $result = $this->manager->checkAndApply(5);

        $this->assertSame($action, $result['action']);
        $this->assertTrue($result['mismatch']);
        $this->assertFalse($result['updated']);
    }

    public static function reviewCases(): array
    {
        return [
            'amount differs'         => [Order::STATE_PENDING_PAYMENT, false, 'fraud'],
            'paid but cancelled'     => [Order::STATE_CANCELED, true, 'review'],
        ];
    }

    public function testCheckAndApplyLeavesAnOrderInSyncAlone(): void
    {
        $this->givenOrders($this->order(), $this->order());
        $this->orderReconciler->method('fetchStatus')->willReturn($this->response('Created'));
        $this->orderReconciler->expects($this->never())->method('reconcile');

        $result = $this->manager->checkAndApply(5);

        $this->assertSame('none', $result['action']);
        $this->assertFalse($result['updated']);
    }

    /**
     * Another process holds the lock. The check result is kept, with the busy
     * message, so the admin sees the status and can use Update to retry.
     */
    public function testCheckAndApplyKeepsTheCheckResultWhenTheOrderIsLocked(): void
    {
        $freshOrder = $this->order();
        $freshOrder->method('canCancel')->willReturn(true);
        $this->givenOrders($this->order(), $freshOrder);
        $this->orderReconciler->method('fetchStatus')->willReturn($this->response('Abandoned'));
        $this->orderReconciler->method('reconcile')->willReturn(OrderReconciler::RESULT_BUSY);

        $result = $this->manager->checkAndApply(5);

        $this->assertTrue($result['success']);
        $this->assertSame('cancel', $result['action']);
        $this->assertTrue($result['mismatch']);
        $this->assertFalse($result['updated']);
        $this->assertStringContainsString('already being updated', $result['message']);
    }

    public function testCheckAndApplyDoesNothingWhenPayflexCannotBeRead(): void
    {
        $this->orderRepository->method('get')->willReturn($this->order());
        $this->orderReconciler->method('fetchStatus')->willReturn([]);
        $this->orderReconciler->expects($this->never())->method('reconcile');

        $result = $this->manager->checkAndApply(5);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['updated']);
    }

    /**
     * The repository returns the stale order, and the factory reloads the fresh one.
     */
    private function givenOrders(Order $staleOrder, Order $freshOrder): void
    {
        $this->orderRepository->method('get')->with(5)->willReturn($staleOrder);
        $freshOrder->method('loadByIncrementId')->with('100000001')->willReturnSelf();
        $this->orderFactory->method('create')->willReturn($freshOrder);
    }

    /**
     * An order whose order and payment must never be saved.
     */
    private function order(): Order
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
        $payment->expects($this->never())->method('save');

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(5);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getPayment')->willReturn($payment);
        $order->expects($this->never())->method('save');

        return $order;
    }

    private function response(string $status): array
    {
        return [
            'orderId'           => 'pf-1',
            'orderStatus'       => $status,
            'merchantReference' => '100000001',
            'amount'            => 150,
        ];
    }
}
