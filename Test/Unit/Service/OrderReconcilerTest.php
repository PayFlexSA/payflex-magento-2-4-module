<?php

namespace Payflex\Gateway\Test\Unit\Service;

use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
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

    /** @var OrderReconciler */
    private $reconciler;

    protected function setUp(): void
    {
        $this->communication = $this->createMock(Communication::class);
        $this->orderFinalizer = $this->createMock(OrderFinalizer::class);
        $this->requestTokenResource = $this->createMock(RequestTokenResource::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);

        $this->reconciler = new OrderReconciler(
            $this->createMock(PayflexLogger::class),
            $this->communication,
            $this->orderFinalizer,
            $this->requestTokenResource,
            $this->lockManager
        );
    }

    public function testReconcileReportsBusyWhenTheOrderIsLocked(): void
    {
        $this->lockManager->method('lock')->willReturn(false);
        $this->lockManager->expects($this->never())->method('unlock');
        $this->communication->expects($this->never())->method('getTransactionStatus');

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

    public function testPayflexIdIsReadFromThePaymentWhenPresent(): void
    {
        $this->requestTokenResource->expects($this->never())->method('getPayflexIds');

        $this->assertSame('pf-from-payment', $this->reconciler->getPayflexId($this->orderWithPayflexId('pf-from-payment')));
    }

    public function testPayflexIdFallsBackToTheStoredRequestToken(): void
    {
        $this->requestTokenResource->method('getPayflexIds')->with('100000001')->willReturn(['pf-from-token']);

        $this->assertSame('pf-from-token', $this->reconciler->getPayflexId($this->orderWithoutPayflexId()));
    }

    public function testPayflexIdFallsBackToTheNewestStoredRequestToken(): void
    {
        $this->requestTokenResource->method('getPayflexIds')->willReturn(['pf-oldest', 'pf-middle', 'pf-newest']);

        $this->assertSame('pf-newest', $this->reconciler->getPayflexId($this->orderWithoutPayflexId()));
    }

    public function testFetchStatusReturnsAnEmptyArrayWhenNoPayflexIdIsKnown(): void
    {
        $this->requestTokenResource->method('getPayflexIds')->willReturn([]);
        $this->communication->expects($this->never())->method('getTransactionStatus');

        $this->assertSame([], $this->reconciler->fetchStatus($this->orderWithoutPayflexId()));
    }

    private function orderWithPayflexId(string $payflexId): Order
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn(['orderId' => $payflexId]);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    private function orderWithoutPayflexId(): Order
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn([]);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }
}
