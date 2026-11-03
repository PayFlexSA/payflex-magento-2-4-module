<?php

namespace Payflex\Gateway\Test\Unit\Controller\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect as ResultRedirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Payflex\Gateway\Controller\Order\Redirect;
use Payflex\Gateway\Helper\Payflex\UrlCreator;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\Payment;
use Payflex\Gateway\Model\ResourceModel\RequestToken as RequestTokenResource;
use Payflex\Gateway\Service\OrderFinalizer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers when the redirect controller sends the customer to Payflex or back to the cart.
 */
class RedirectTest extends TestCase
{
    /** @var CheckoutSession&MockObject */
    private $checkoutSession;

    /** @var UrlCreator&MockObject */
    private $urlCreator;

    /** @var RequestTokenResource&MockObject */
    private $requestTokenResource;

    /** @var OrderFinalizer&MockObject */
    private $orderFinalizer;

    /** @var ResultRedirect&MockObject */
    private $resultRedirect;

    /** @var Redirect */
    private $controller;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLastRealOrder', 'restoreQuote'])
            ->getMock();
        $this->urlCreator = $this->createMock(UrlCreator::class);
        $this->requestTokenResource = $this->createMock(RequestTokenResource::class);
        $this->orderFinalizer = $this->createMock(OrderFinalizer::class);

        $this->resultRedirect = $this->createMock(ResultRedirect::class);
        $this->resultRedirect->method('setUrl')->willReturnSelf();
        $this->resultRedirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->resultRedirect);

        $context = $this->createMock(Context::class);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($this->createMock(ManagerInterface::class));

        $this->controller = new Redirect(
            $context,
            $this->checkoutSession,
            $this->urlCreator,
            $this->requestTokenResource,
            $this->orderFinalizer,
            $this->createMock(PayflexLogger::class)
        );
    }

    public function testRedirectsToPayflexForAPendingPayflexOrder(): void
    {
        $order = $this->order(Payment::Payflex_Gateway_CODE, Order::STATE_PENDING_PAYMENT);
        $this->urlCreator->expects($this->once())->method('createUrl')->with($order)
            ->willReturn('https://payflex.example/checkout');

        $this->resultRedirect->expects($this->once())->method('setUrl')->with('https://payflex.example/checkout');
        $this->resultRedirect->expects($this->never())->method('setPath');
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->controller->execute();
    }

    public function testSendsToCartWhenThereIsNoOrder(): void
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $order->method('getId')->willReturn(null);
        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);

        $this->expectCartWithoutSession();

        $this->controller->execute();
    }

    public function testSendsToCartForAnotherPaymentMethod(): void
    {
        $this->order('checkmo', Order::STATE_PENDING_PAYMENT);

        $this->expectCartWithoutSession();

        $this->controller->execute();
    }

    public function testSendsToCartWhenTheOrderIsNotPendingPayment(): void
    {
        $this->order(Payment::Payflex_Gateway_CODE, Order::STATE_PROCESSING);

        $this->expectCartWithoutSession();

        $this->controller->execute();
    }

    public function testCancelsTheOrderWhenTheFirstSessionFails(): void
    {
        $order = $this->order(Payment::Payflex_Gateway_CODE, Order::STATE_PENDING_PAYMENT);
        $this->urlCreator->method('createUrl')->willThrowException(new \Exception('timeout'));
        $this->requestTokenResource->method('getPayflexIds')->with('100000001')->willReturn([]);

        $this->orderFinalizer->expects($this->once())->method('cancel')->with($order);
        $this->checkoutSession->expects($this->once())->method('restoreQuote');
        $this->resultRedirect->expects($this->once())->method('setPath')->with('checkout/cart');

        $this->controller->execute();
    }

    public function testKeepsTheOrderWhenAnEarlierSessionExists(): void
    {
        $this->order(Payment::Payflex_Gateway_CODE, Order::STATE_PENDING_PAYMENT);
        $this->urlCreator->method('createUrl')->willReturn('');
        $this->requestTokenResource->method('getPayflexIds')->with('100000001')->willReturn(['pf-old']);

        $this->orderFinalizer->expects($this->never())->method('cancel');
        $this->checkoutSession->expects($this->once())->method('restoreQuote');
        $this->resultRedirect->expects($this->once())->method('setPath')->with('checkout/cart');

        $this->controller->execute();
    }

    private function order(string $method, string $state): Order
    {
        $payment = $this->getMockBuilder(OrderPayment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMethod'])
            ->getMock();
        $payment->method('getMethod')->willReturn($method);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getIncrementId', 'getPayment', 'getState'])
            ->getMock();
        $order->method('getId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn($state);

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);

        return $order;
    }

    private function expectCartWithoutSession(): void
    {
        $this->urlCreator->expects($this->never())->method('createUrl');
        $this->orderFinalizer->expects($this->never())->method('cancel');
        $this->checkoutSession->expects($this->never())->method('restoreQuote');
        $this->resultRedirect->expects($this->once())->method('setPath')->with('checkout/cart');
    }
}
