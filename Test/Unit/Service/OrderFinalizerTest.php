<?php

namespace Payflex\Gateway\Test\Unit\Service;

use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder as TransactionBuilder;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Service\OrderFinalizer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderFinalizerTest extends TestCase
{
    /** @var TransactionBuilder&MockObject */
    private $transactionBuilder;

    /** @var Configuration&MockObject */
    private $configuration;

    /** @var OrderManagementInterface&MockObject */
    private $orderManagement;

    /** @var OrderFinalizer */
    private $finalizer;

    protected function setUp(): void
    {
        $this->transactionBuilder = $this->createMock(TransactionBuilder::class);
        $this->configuration = $this->createMock(Configuration::class);
        $this->orderManagement = $this->createMock(OrderManagementInterface::class);

        // The cancel path asks the store manager for the current store id, so a
        // bare mock (whose getStore() returns null) would fatal. Wire a store.
        $store = $this->createMock(\Magento\Store\Api\Data\StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->finalizer = new OrderFinalizer(
            $this->createMock(PayflexLogger::class),
            $this->configuration,
            $storeManager,
            $this->createMock(OrderSender::class),
            $this->createMock(InvoiceService::class),
            $this->createMock(InvoiceSender::class),
            $this->transactionBuilder,
            $this->createMock(TransactionFactory::class),
            $this->orderManagement
        );
    }

    public function testCreateTransactionRejectsWhenNotApproved(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->expects($this->never())->method('getPayment');

        $result = $this->finalizer->createTransaction($order, [
            'orderStatus'       => 'Declined',
            'merchantReference' => '100000001',
            'orderId'           => 'pf-1',
        ]);

        $this->assertFalse($result);
    }

    /**
     * Regression guard for B-9: an Approved response whose merchantReference does
     * NOT match the order must be rejected. The old `&&` guard let it through.
     */
    public function testCreateTransactionRejectsApprovedWithMismatchedReference(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->expects($this->never())->method('getPayment');

        $result = $this->finalizer->createTransaction($order, [
            'orderStatus'       => 'Approved',
            'merchantReference' => 'SOMEONE-ELSE',
            'orderId'           => 'pf-1',
        ]);

        $this->assertFalse($result);
    }

    public function testCreateTransactionCapturesWhenApprovedAndMatching(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('setLastTransId')->willReturnSelf();
        $payment->method('setTransactionId')->willReturnSelf();

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getGrandTotal')->willReturn(150.0);
        $order->method('getBaseCurrency')->willReturn(
            $this->configuredCurrency()
        );

        $transaction = $this->createMock(Transaction::class);
        $transaction->method('save')->willReturnSelf();
        $transaction->method('getTransactionId')->willReturn('pf-1');

        $this->transactionBuilder->method('setPayment')->willReturnSelf();
        $this->transactionBuilder->method('setOrder')->willReturnSelf();
        $this->transactionBuilder->method('setTransactionId')->willReturnSelf();
        $this->transactionBuilder->method('setAdditionalInformation')->willReturnSelf();
        $this->transactionBuilder->method('setFailSafe')->willReturnSelf();
        $this->transactionBuilder->method('build')->willReturn($transaction);

        $result = $this->finalizer->createTransaction($order, [
            'orderStatus'       => 'Approved',
            'merchantReference' => '100000001',
            'orderId'           => 'pf-1',
        ]);

        $this->assertSame('pf-1', $result);
    }

    /**
     * cancel() is the decline/abandon counterpart to the capture tests above.
     * It must refuse to cancel an order that has already progressed to the
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

    private function configuredCurrency()
    {
        $currency = $this->getMockBuilder(\Magento\Directory\Model\Currency::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['formatTxt'])
            ->getMock();
        $currency->method('formatTxt')->willReturn('R150.00');
        return $currency;
    }
}
