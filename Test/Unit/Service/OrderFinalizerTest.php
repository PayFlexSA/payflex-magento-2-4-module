<?php

namespace Payflex\Gateway\Test\Unit\Service;

use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as TransactionRepository;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Service\OrderFinalizer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderFinalizerTest extends TestCase
{
    /** @var Configuration&MockObject */
    private $configuration;

    /** @var OrderManagementInterface&MockObject */
    private $orderManagement;

    /** @var OrderFinalizer */
    private $finalizer;

    protected function setUp(): void
    {
        $this->configuration = $this->createMock(Configuration::class);
        $this->orderManagement = $this->createMock(OrderManagementInterface::class);

        $this->finalizer = new OrderFinalizer(
            $this->createMock(PayflexLogger::class),
            $this->configuration,
            $this->createMock(OrderSender::class),
            $this->createMock(InvoiceSender::class),
            $this->orderManagement,
            $this->createMock(TransactionRepository::class)
        );
    }

    /**
     * cancel() must refuse to cancel an order that has already progressed to the
     * configured "new order" status (i.e. it was actually paid/processed).
     */
    public function testCancelIsNoOpWhenOrderAlreadyAtNewOrderStatus(): void
    {
        $this->configuration->method('getPayflexNewOrderStatus')->willReturn('processing');

        $order = $this->createMock(Order::class);
        $order->method('getStatus')->willReturn('processing');
        // A no-op must not attempt to cancel the order.
        $order->expects($this->never())->method('cancel');
        $this->orderManagement->expects($this->never())->method('cancel');

        $this->assertFalse($this->finalizer->cancel($order, 'declined'));
    }

    public function testCancelIsNoOpWhenOrderCannotBeCancelled(): void
    {
        $this->configuration->method('getPayflexNewOrderStatus')->willReturn('processing');

        $order = $this->createMock(Order::class);
        $order->method('getStatus')->willReturn('pending_payment');
        $order->method('canCancel')->willReturn(false); // e.g. already invoiced/closed
        $order->expects($this->never())->method('cancel');
        $this->orderManagement->expects($this->never())->method('cancel');

        $this->assertFalse($this->finalizer->cancel($order, 'declined'));
    }

    public function testCancelCancelsAPendingOrder(): void
    {
        $this->configuration->method('getPayflexNewOrderStatus')->willReturn('processing');

        $order = $this->createMock(Order::class);
        $order->method('getStatus')->willReturn('pending_payment');
        $order->method('canCancel')->willReturn(true);
        $order->method('getId')->willReturn(55);
        $order->method('setStatus')->willReturnSelf();
        $order->method('addStatusHistoryComment')->willReturnSelf();

        // The order is moved to the canceled state and cancelled both via the
        // order-management service (by id) and the model itself.
        $order->expects($this->once())->method('setState')
            ->with(Order::STATE_CANCELED)->willReturnSelf();
        $this->orderManagement->expects($this->once())->method('cancel')->with(55);
        $order->expects($this->once())->method('cancel');
        $order->expects($this->once())->method('save');

        $this->assertTrue($this->finalizer->cancel($order, 'Payflex CRON: transaction declined'));
    }
}
