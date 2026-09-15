<?php

namespace Payflex\Gateway\Test\Unit\Service;

use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\RequestToken;
use Payflex\Gateway\Model\RequestTokenFactory;
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

    /** @var RequestTokenFactory&MockObject */
    private $requestTokenFactory;

    /** @var LockManagerInterface&MockObject */
    private $lockManager;

    /** @var OrderReconciler */
    private $reconciler;

    protected function setUp(): void
    {
        $this->communication = $this->createMock(Communication::class);
        $this->orderFinalizer = $this->createMock(OrderFinalizer::class);
        $this->requestTokenFactory = $this->getMockBuilder(RequestTokenFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->lockManager = $this->createMock(LockManagerInterface::class);

        $this->reconciler = new OrderReconciler(
            $this->createMock(PayflexLogger::class),
            $this->communication,
            $this->orderFinalizer,
            $this->requestTokenFactory,
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

        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn([]);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getPayment')->willReturn($payment);

        $token = $this->getMockBuilder(RequestToken::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->addMethods(['getPayflexId'])
            ->getMock();
        $token->method('load')->willReturnSelf();
        $token->method('getPayflexId')->willReturn(null);
        $this->requestTokenFactory->method('create')->willReturn($token);

        $this->assertSame(OrderFinalizer::RESULT_NONE, $this->reconciler->reconcile($order, 'Payflex webhook'));
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
        $this->requestTokenFactory->expects($this->never())->method('create');

        $this->assertSame('pf-from-payment', $this->reconciler->getPayflexId($this->orderWithPayflexId('pf-from-payment')));
    }

    public function testPayflexIdFallsBackToTheStoredRequestToken(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn([]);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getPayment')->willReturn($payment);

        $token = $this->getMockBuilder(RequestToken::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->addMethods(['getPayflexId'])
            ->getMock();
        $token->method('load')->willReturnSelf();
        $token->method('getPayflexId')->willReturn('pf-from-token');

        $this->requestTokenFactory->method('create')->willReturn($token);

        $this->assertSame('pf-from-token', $this->reconciler->getPayflexId($order));
    }

    public function testFetchStatusReturnsAnEmptyArrayWhenNoPayflexIdIsKnown(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn([]);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getPayment')->willReturn($payment);

        $token = $this->getMockBuilder(RequestToken::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->addMethods(['getPayflexId'])
            ->getMock();
        $token->method('load')->willReturnSelf();
        $token->method('getPayflexId')->willReturn(null);

        $this->requestTokenFactory->method('create')->willReturn($token);
        $this->communication->expects($this->never())->method('getTransactionStatus');

        $this->assertSame([], $this->reconciler->fetchStatus($order));
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
}
