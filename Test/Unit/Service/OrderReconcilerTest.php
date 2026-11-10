<?php

namespace Payflex\Gateway\Test\Unit\Service;

use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\ResourceModel\RequestToken as RequestTokenResource;
use Payflex\Gateway\Service\OrderFinalizer;
use Payflex\Gateway\Service\OrderReconciler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the lookup shared by the webhook, the cron and the admin button.
 */
class OrderReconcilerTest extends TestCase
{
    /** @var Communication&MockObject */
    private $communication;

    /** @var OrderFinalizer&MockObject */
    private $orderFinalizer;

    /** @var RequestTokenResource&MockObject */
    private $requestTokenResource;

    /** @var LockManagerInterface&MockObject */
    private $lockManager;

    /** @var OrderFactory&MockObject */
    private $orderFactory;

    /** @var OrderReconciler */
    private $reconciler;

    protected function setUp(): void
    {
        $this->communication = $this->createMock(Communication::class);
        $this->orderFinalizer = $this->createMock(OrderFinalizer::class);
        $this->requestTokenResource = $this->createMock(RequestTokenResource::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->orderFactory = $this->createMock(OrderFactory::class);

        $this->reconciler = new OrderReconciler(
            $this->createMock(PayflexLogger::class),
            $this->communication,
            $this->orderFinalizer,
            $this->requestTokenResource,
            $this->lockManager,
            $this->orderFactory
        );
    }

    public function testReconcileReportsBusyWhenTheOrderIsLocked(): void
    {
        $this->lockManager->method('lock')->willReturn(false);
        $this->lockManager->expects($this->never())->method('unlock');
        $this->communication->expects($this->never())->method('getTransactionStatus');
        $this->orderFactory->expects($this->never())->method('create');

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');

        $this->assertSame(OrderReconciler::RESULT_BUSY, $this->reconciler->reconcile($order, 'Payflex webhook'));
    }

    public function testReconcileSkipsOrdersThatAreAlreadyFinalized(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock');
        $this->orderFinalizer->method('isFinalized')->willReturn(true);
        $this->communication->expects($this->never())->method('getTransactionStatus');

        $order = $this->loadedOrder($this->createMock(Order::class));
        $order->method('getIncrementId')->willReturn('100000001');

        $this->assertSame(OrderFinalizer::RESULT_NONE, $this->reconciler->reconcile($order, 'Payflex CRON'));
    }

    /**
     * The order is loaded again once the lock is held, and only that copy is
     * checked and finalised.
     */
    public function testReconcileWorksOnTheOrderReloadedInsideTheLock(): void
    {
        $locked = false;
        $this->lockManager->method('lock')->willReturnCallback(function () use (&$locked) {
            $locked = true;
            return true;
        });

        $staleOrder = $this->createMock(Order::class);
        $staleOrder->method('getIncrementId')->willReturn('100000001');
        $staleOrder->expects($this->never())->method('getPayment');

        $freshOrder = $this->orderWithPayflexId('pf-1');
        $this->orderFactory->expects($this->once())->method('create')
            ->willReturnCallback(function () use (&$locked, $freshOrder) {
                $this->assertTrue($locked, 'The order must be loaded after the lock is taken.');
                return $freshOrder;
            });

        $response = $this->response('pf-1', 'Approved');
        $this->communication->method('getTransactionStatus')->willReturn($response);
        $this->orderFinalizer->expects($this->once())->method('isFinalized')->with($freshOrder)->willReturn(false);
        $this->orderFinalizer->expects($this->once())
            ->method('finalizeFromStatus')
            ->with($freshOrder, $response, 'Payflex webhook')
            ->willReturn(OrderFinalizer::RESULT_CAPTURED);

        $this->assertSame(OrderFinalizer::RESULT_CAPTURED, $this->reconciler->reconcile($staleOrder, 'Payflex webhook'));
    }

    public function testReconcileReportsNoActionWhenTheOrderCannotBeReloaded(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock');
        $this->orderFinalizer->expects($this->never())->method('isFinalized');
        $this->communication->expects($this->never())->method('getTransactionStatus');

        $missingOrder = $this->createMock(Order::class);
        $missingOrder->method('loadByIncrementId')->willReturnSelf();
        $missingOrder->method('getId')->willReturn(null);
        $this->orderFactory->method('create')->willReturn($missingOrder);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');

        $this->assertSame(OrderFinalizer::RESULT_NONE, $this->reconciler->reconcile($order, 'Payflex CRON'));
    }

    public function testReconcileThrowsWhenPayflexCannotBeRead(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock');
        $this->orderFinalizer->method('isFinalized')->willReturn(false);
        $this->communication->method('getTransactionStatus')->willReturn([]);

        $this->expectException(NotFoundException::class);

        $this->reconciler->reconcile($this->orderWithPayflexId('pf-1'), 'Payflex webhook');
    }

    public function testReconcileReleasesTheLockWhenTheLookupThrows(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock');
        $this->orderFinalizer->method('isFinalized')->willReturn(false);
        $this->communication->method('getTransactionStatus')->willReturn([]);

        try {
            $this->reconciler->reconcile($this->orderWithPayflexId('pf-1'), 'Payflex webhook');
            $this->fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * An order with no Payflex id can never be reconciled, so the caller must
     * not be told to retry.
     */
    public function testReconcileReportsNoActionWhenThereIsNoPayflexId(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock');
        $this->orderFinalizer->method('isFinalized')->willReturn(false);
        $this->communication->expects($this->never())->method('getTransactionStatus');
        $this->requestTokenResource->method('getPayflexIds')->willReturn([]);

        $this->assertSame(
            OrderFinalizer::RESULT_NONE,
            $this->reconciler->reconcile($this->orderWithoutPayflexId(), 'Payflex webhook')
        );
    }

    public function testReconcileHandsTheLiveStatusToTheFinalizer(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->orderFinalizer->method('isFinalized')->willReturn(false);

        $response = [
            'orderStatus'       => 'Approved',
            'merchantReference' => '100000001',
            'orderId'           => 'pf-1',
        ];
        $this->communication->method('getTransactionStatus')->willReturn($response);

        $this->orderFinalizer->expects($this->once())
            ->method('finalizeFromStatus')
            ->with($this->anything(), $response, 'Payflex webhook')
            ->willReturn(OrderFinalizer::RESULT_CAPTURED);

        $result = $this->reconciler->reconcile($this->orderWithPayflexId('pf-1'), 'Payflex webhook');

        $this->assertSame(OrderFinalizer::RESULT_CAPTURED, $result);
    }

    public function testFinalizedOrderWithOneAttemptMakesNoLookup(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->orderFinalizer->method('isFinalized')->willReturn(true);
        $this->requestTokenResource->method('getPayflexIds')->willReturn(['pf-1']);
        $this->communication->expects($this->never())->method('getTransactionStatus');

        $this->assertSame(
            OrderFinalizer::RESULT_NONE,
            $this->reconciler->reconcile($this->orderWithPayflexId('pf-1'), 'Payflex webhook')
        );
    }

    /**
     * A second attempt paid after the order was captured is flagged for a
     * refund once, and is not looked up again after that.
     */
    public function testLateApprovalOnAFinalizedOrderIsFlaggedOnce(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->orderFinalizer->method('isFinalized')->willReturn(true);
        $this->orderFinalizer->expects($this->never())->method('finalizeFromStatus');
        $this->requestTokenResource->method('getPayflexIds')->willReturn(['pf-old', 'pf-paid']);
        $this->communication->expects($this->once())
            ->method('getTransactionStatus')
            ->with('pf-old')
            ->willReturn($this->response('pf-old', 'Approved'));

        $order = $this->orderWithPayflexId('pf-paid');
        $order->expects($this->once())
            ->method('addStatusHistoryComment')
            ->with($this->logicalAnd($this->stringContains('pf-paid'), $this->stringContains('pf-old')))
            ->willReturn($this->history());

        $this->reconciler->reconcile($order, 'Payflex webhook');
        $this->reconciler->reconcile($order, 'Payflex webhook');

        $this->assertSame(['pf-old'], $order->getPayment()->getAdditionalInformation(OrderReconciler::FLAGGED_IDS_KEY));
    }

    public function testLateApprovalLookupFailureIsIgnored(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->orderFinalizer->method('isFinalized')->willReturn(true);
        $this->requestTokenResource->method('getPayflexIds')->willReturn(['pf-old', 'pf-paid']);
        $this->communication->method('getTransactionStatus')->willThrowException(new \Exception('timeout'));

        $order = $this->orderWithPayflexId('pf-paid');
        $order->expects($this->never())->method('addStatusHistoryComment');

        $this->assertSame(OrderFinalizer::RESULT_NONE, $this->reconciler->reconcile($order, 'Payflex webhook'));
    }

    public function testPayflexIdsAreTheStoredRowsOldestFirst(): void
    {
        $this->requestTokenResource->method('getPayflexIds')->with('100000001')->willReturn(['pf-old', 'pf-new']);

        $this->assertSame(['pf-old', 'pf-new'], $this->reconciler->getPayflexIds($this->orderWithPayflexId('pf-new')));
    }

    public function testPayflexIdOnThePaymentIsAddedWhenItHasNoRow(): void
    {
        $this->requestTokenResource->method('getPayflexIds')->willReturn(['pf-old']);

        $this->assertSame(['pf-old', 'pf-legacy'], $this->reconciler->getPayflexIds($this->orderWithPayflexId('pf-legacy')));
    }

    public function testPayflexIdsFallBackToTheStoredRowsWithoutAPaymentId(): void
    {
        $this->requestTokenResource->method('getPayflexIds')->willReturn(['pf-oldest', 'pf-newest']);

        $this->assertSame(['pf-oldest', 'pf-newest'], $this->reconciler->getPayflexIds($this->orderWithoutPayflexId()));
    }

    public function testOlderApprovedAttemptIsCapturedWhileANewerOneIsOpen(): void
    {
        $approved = $this->response('pf-old', 'Approved');
        $this->givenAttempts(['pf-old' => $approved, 'pf-new' => $this->response('pf-new', 'Created')]);
        $this->orderFinalizer->method('amountMatches')->willReturn(true);

        $this->expectFinalizedWith($approved, OrderFinalizer::RESULT_CAPTURED);

        $this->assertSame(
            OrderFinalizer::RESULT_CAPTURED,
            $this->reconciler->reconcile($this->orderWithPayflexId('pf-new'), 'Payflex webhook')
        );
    }

    public function testOpenAttemptIsUsedWhenAnotherWasAbandoned(): void
    {
        $created = $this->response('pf-old', 'Created');
        $this->givenAttempts(['pf-old' => $created, 'pf-new' => $this->response('pf-new', 'Abandoned')]);

        $this->expectFinalizedWith($created, OrderFinalizer::RESULT_NONE);

        $this->assertSame(
            OrderFinalizer::RESULT_NONE,
            $this->reconciler->reconcile($this->orderWithPayflexId('pf-new'), 'Payflex CRON')
        );
    }

    public function testNewestAttemptIsCancelledWhenEveryAttemptFailed(): void
    {
        $declined = $this->response('pf-new', 'Declined');
        $this->givenAttempts(['pf-old' => $this->response('pf-old', 'Abandoned'), 'pf-new' => $declined]);

        $this->expectFinalizedWith($declined, OrderFinalizer::RESULT_CANCELLED);

        $this->assertSame(
            OrderFinalizer::RESULT_CANCELLED,
            $this->reconciler->reconcile($this->orderWithPayflexId('pf-new'), 'Payflex CRON')
        );
    }

    public function testFailedLookupThrowsWhenNothingIsApproved(): void
    {
        $this->givenAttempts(['pf-old' => [], 'pf-new' => $this->response('pf-new', 'Abandoned')]);
        $this->orderFinalizer->expects($this->never())->method('finalizeFromStatus');

        $this->expectException(NotFoundException::class);

        $this->reconciler->reconcile($this->orderWithPayflexId('pf-new'), 'Payflex CRON');
    }

    public function testApprovedAttemptIsCapturedWhenAnotherLookupFailed(): void
    {
        $approved = $this->response('pf-new', 'Approved');
        $this->givenAttempts(['pf-old' => [], 'pf-new' => $approved]);
        $this->orderFinalizer->method('amountMatches')->willReturn(true);

        $this->expectFinalizedWith($approved, OrderFinalizer::RESULT_CAPTURED);

        $this->assertSame(
            OrderFinalizer::RESULT_CAPTURED,
            $this->reconciler->reconcile($this->orderWithPayflexId('pf-new'), 'Payflex webhook')
        );
    }

    /**
     * The approval whose amount matches the order is used, and the other one
     * is named in an order comment so that it can be refunded.
     */
    public function testTwoApprovedAttemptsCaptureOneAndCommentOnTheOther(): void
    {
        $wrongAmount = $this->response('pf-old', 'Approved');
        $rightAmount = $this->response('pf-new', 'Approved');
        $this->givenAttempts(['pf-old' => $wrongAmount, 'pf-new' => $rightAmount]);
        $this->orderFinalizer->method('amountMatches')->willReturnCallback(function ($order, $response) {
            return $response['orderId'] === 'pf-new';
        });

        $order = $this->orderWithPayflexId('pf-new');
        $order->expects($this->once())
            ->method('addStatusHistoryComment')
            ->with($this->logicalAnd($this->stringContains('pf-new'), $this->stringContains('pf-old')))
            ->willReturn($this->history());

        $this->expectFinalizedWith($rightAmount, OrderFinalizer::RESULT_CAPTURED);

        $this->assertSame(OrderFinalizer::RESULT_CAPTURED, $this->reconciler->reconcile($order, 'Payflex webhook'));
    }

    public function testExtraApprovalThatWasAlreadyFlaggedIsNotFlaggedAgain(): void
    {
        $wrongAmount = $this->response('pf-old', 'Approved');
        $rightAmount = $this->response('pf-new', 'Approved');
        $this->givenAttempts(['pf-old' => $wrongAmount, 'pf-new' => $rightAmount]);
        $this->orderFinalizer->method('amountMatches')->willReturnCallback(function ($order, $response) {
            return $response['orderId'] === 'pf-new';
        });

        $order = $this->orderWithPayflexId('pf-new');
        $order->getPayment()->setAdditionalInformation(OrderReconciler::FLAGGED_IDS_KEY, ['pf-old']);
        $order->expects($this->never())->method('addStatusHistoryComment');

        $this->expectFinalizedWith($rightAmount, OrderFinalizer::RESULT_CAPTURED);

        $this->reconciler->reconcile($order, 'Payflex webhook');
    }

    public function testFetchStatusUsesTheSameSelectionAsReconcile(): void
    {
        $approved = $this->response('pf-old', 'Approved');
        $this->givenAttempts(['pf-old' => $approved, 'pf-new' => $this->response('pf-new', 'Created')]);
        $this->orderFinalizer->method('amountMatches')->willReturn(true);

        $this->assertSame($approved, $this->reconciler->fetchStatus($this->orderWithPayflexId('pf-new')));
    }

    public function testFetchStatusReturnsAnEmptyArrayWhenAFailedLookupLeavesItUndecided(): void
    {
        $this->givenAttempts(['pf-old' => [], 'pf-new' => $this->response('pf-new', 'Created')]);

        $this->assertSame([], $this->reconciler->fetchStatus($this->orderWithPayflexId('pf-new')));
    }

    public function testFetchStatusReturnsAnEmptyArrayWhenNoPayflexIdIsKnown(): void
    {
        $this->requestTokenResource->method('getPayflexIds')->willReturn([]);
        $this->communication->expects($this->never())->method('getTransactionStatus');

        $this->assertSame([], $this->reconciler->fetchStatus($this->orderWithoutPayflexId()));
    }

    /**
     * Store one row per attempt, oldest first, and answer each lookup with its response.
     */
    private function givenAttempts(array $responses): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->orderFinalizer->method('isFinalized')->willReturn(false);
        $this->requestTokenResource->method('getPayflexIds')->willReturn(array_keys($responses));
        $this->communication->method('getTransactionStatus')->willReturnCallback(function ($payflexId) use ($responses) {
            return $responses[$payflexId];
        });
    }

    private function expectFinalizedWith(array $response, string $result): void
    {
        $this->orderFinalizer->expects($this->once())
            ->method('finalizeFromStatus')
            ->with($this->anything(), $response, $this->anything())
            ->willReturn($result);
    }

    private function response(string $payflexId, string $status): array
    {
        return [
            'orderId'           => $payflexId,
            'orderStatus'       => $status,
            'merchantReference' => '100000001',
            'amount'            => 150,
        ];
    }

    private function orderWithPayflexId(string $payflexId): Order
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
        $payment->setAdditionalInformation('orderId', $payflexId);

        $order = $this->loadedOrder($this->createMock(Order::class));
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    private function orderWithoutPayflexId(): Order
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn([]);

        $order = $this->loadedOrder($this->createMock(Order::class));
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    /**
     * Make the order the one that reconcile() loads once it holds the lock.
     */
    private function loadedOrder(Order $order): Order
    {
        $order->method('getId')->willReturn(1);
        $order->method('loadByIncrementId')->with('100000001')->willReturnSelf();
        $this->orderFactory->method('create')->willReturn($order);

        return $order;
    }

    private function history(): \Magento\Sales\Model\Order\Status\History
    {
        $history = $this->createMock(\Magento\Sales\Model\Order\Status\History::class);
        $history->method('setIsCustomerNotified')->willReturnSelf();

        return $history;
    }
}
