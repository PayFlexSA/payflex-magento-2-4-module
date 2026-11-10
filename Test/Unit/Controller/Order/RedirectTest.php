<?php

namespace Payflex\Gateway\Test\Unit\Controller\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Redirect as ResultRedirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Sales\Model\OrderFactory;
use Payflex\Gateway\Controller\Order\Redirect;
use Payflex\Gateway\Helper\Payflex\UrlCreator;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\Payment;
use Payflex\Gateway\Model\ResourceModel\RequestToken as RequestTokenResource;
use Payflex\Gateway\Service\OrderFinalizer;
use Payflex\Gateway\Service\OrderReconciler;
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

    /** @var OrderReconciler&MockObject */
    private $orderReconciler;

    /** @var OrderFactory&MockObject */
    private $orderFactory;

    /** @var ResultRedirect&MockObject */
    private $resultRedirect;

    /** @var HttpRequest&MockObject */
    private $request;

    /** @var ManagerInterface&MockObject */
    private $messageManager;

    /** @var Redirect */
    private $controller;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLastRealOrder', 'restoreQuote', 'setLoadInactive'])
            ->getMock();
        $this->urlCreator = $this->createMock(UrlCreator::class);
        $this->requestTokenResource = $this->createMock(RequestTokenResource::class);
        $this->orderFinalizer = $this->createMock(OrderFinalizer::class);
        $this->orderReconciler = $this->createMock(OrderReconciler::class);
        $this->orderFactory = $this->createMock(OrderFactory::class);

        $this->resultRedirect = $this->createMock(ResultRedirect::class);
        $this->resultRedirect->method('setUrl')->willReturnSelf();
        $this->resultRedirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->resultRedirect);

        $this->request = $this->createMock(HttpRequest::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);

        $context = $this->createMock(Context::class);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getRequest')->willReturn($this->request);

        $this->controller = new Redirect(
            $context,
            $this->checkoutSession,
            $this->urlCreator,
            $this->requestTokenResource,
            $this->orderFinalizer,
            $this->orderReconciler,
            $this->orderFactory,
            $this->createMock(PayflexLogger::class)
        );
    }

    public function testRedirectsToPayflexForAPendingPayflexOrder(): void
    {
        $order = $this->order(Payment::Payflex_Gateway_CODE, Order::STATE_PENDING_PAYMENT);
        $this->orderReconciler->expects($this->once())->method('lockOrder')
            ->with('100000001', Redirect::LOCK_TIMEOUT)->willReturn(true);
        $this->orderReconciler->expects($this->once())->method('unlockOrder')->with('100000001');
        $this->urlCreator->expects($this->once())->method('createUrl')->with($order)
            ->willReturn('https://payflex.example/checkout');

        $this->resultRedirect->expects($this->once())->method('setUrl')->with('https://payflex.example/checkout');
        $this->resultRedirect->expects($this->never())->method('setPath');
        $this->checkoutSession->expects($this->never())->method('restoreQuote');

        $this->controller->execute();
    }

    /**
     * Another process holds the lock, so the action runs again shortly instead
     * of sending the customer away.
     */
    public function testRetriesWhenTheOrderIsLocked(): void
    {
        $this->order(Payment::Payflex_Gateway_CODE, Order::STATE_PENDING_PAYMENT, false);
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getActionName')->willReturn('redirect');
        $this->request->expects($this->once())->method('setParams')->with(['TriedTime' => 1]);
        $this->request->expects($this->once())->method('setActionName')->with('redirect');
        $this->request->expects($this->once())->method('setDispatched')->with(false);
        $this->orderReconciler->expects($this->never())->method('unlockOrder');
        $this->orderFactory->expects($this->never())->method('create');

        $this->urlCreator->expects($this->never())->method('createUrl');
        $this->orderFinalizer->expects($this->never())->method('cancel');
        $this->checkoutSession->expects($this->never())->method('restoreQuote');
        $this->resultRedirect->expects($this->never())->method('setPath');

        $this->controller->execute();
    }

    public function testSendsToCartWithoutASessionWhenTheOrderStaysLocked(): void
    {
        $this->order(Payment::Payflex_Gateway_CODE, Order::STATE_PENDING_PAYMENT, false);
        $this->request->method('getParams')->willReturn(['TriedTime' => Redirect::MAX_LOCK_TRIES]);
        $this->request->expects($this->never())->method('setDispatched');
        $this->orderReconciler->expects($this->never())->method('unlockOrder');
        $this->orderFactory->expects($this->never())->method('create');
        $this->messageManager->expects($this->once())->method('addErrorMessage')
            ->with($this->stringContains('try again'));

        $this->expectCartWithoutSession();

        $this->controller->execute();
    }

    public function testSendsAPaidOrderToTheSuccessPage(): void
    {
        $this->order(Payment::Payflex_Gateway_CODE, Order::STATE_PROCESSING);
        $this->orderFinalizer->method('isFinalized')->willReturn(true);
        $this->orderReconciler->expects($this->once())->method('unlockOrder')->with('100000001');

        $this->urlCreator->expects($this->never())->method('createUrl');
        $this->checkoutSession->expects($this->never())->method('restoreQuote');
        $this->checkoutSession->expects($this->once())->method('setLoadInactive')->with(false);
        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->resultRedirect->expects($this->once())->method('setPath')
            ->with('checkout/onepage/success', ['_secure' => true]);

        $this->controller->execute();
    }

    /**
     * Payflex took the payment but the order needs review, so the customer is
     * told so and the cart is not restored for a second payment.
     */
    public function testPaidOrderInPaymentReviewShowsTheReviewMessage(): void
    {
        $this->order(Payment::Payflex_Gateway_CODE, Order::STATE_PAYMENT_REVIEW, true, true);
        $this->orderFinalizer->method('isFinalized')->willReturn(true);
        $this->messageManager->expects($this->once())->method('addErrorMessage')
            ->with($this->stringContains('payment went through'));

        $this->expectCartWithoutSession();

        $this->controller->execute();
    }

    /**
     * The order is read again once the lock is held, because another process
     * may have paid or cancelled it in the meantime.
     */
    public function testUsesTheOrderAsReloadedInsideTheLock(): void
    {
        $sessionOrder = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getIncrementId', 'getState'])
            ->getMock();
        $sessionOrder->method('getId')->willReturn(1);
        $sessionOrder->method('getIncrementId')->willReturn('100000001');
        $sessionOrder->expects($this->never())->method('getState');
        $this->checkoutSession->method('getLastRealOrder')->willReturn($sessionOrder);

        $this->order(Payment::Payflex_Gateway_CODE, Order::STATE_PROCESSING);

        $this->orderReconciler->expects($this->once())->method('unlockOrder')->with('100000001');
        $this->expectCartWithoutSession();

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

    private function order(string $method, string $state, bool $lockFree = true, bool $inPaymentReview = false): Order
    {
        $payment = $this->getMockBuilder(OrderPayment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMethod'])
            ->getMock();
        $payment->method('getMethod')->willReturn($method);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getIncrementId', 'getPayment', 'getState', 'isPaymentReview', 'isCanceled', 'loadByIncrementId'])
            ->getMock();
        $order->method('getId')->willReturn(1);
        $order->method('isPaymentReview')->willReturn($inPaymentReview);
        $order->method('isCanceled')->willReturn(false);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn($state);
        $order->method('loadByIncrementId')->with('100000001')->willReturnSelf();

        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);
        $this->orderReconciler->method('lockOrder')->willReturn($lockFree);
        $this->orderFactory->method('create')->willReturn($order);

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
