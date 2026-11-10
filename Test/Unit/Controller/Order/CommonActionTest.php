<?php

namespace Payflex\Gateway\Test\Unit\Controller\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\OrderFactory;
use Payflex\Gateway\Controller\Order\Success;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\RequestToken;
use Payflex\Gateway\Model\RequestTokenFactory;
use Payflex\Gateway\Service\OrderFinalizer;
use Payflex\Gateway\Service\OrderReconciler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the check on the Payflex order id and token the customer returns with,
 * and where the customer is sent afterwards.
 */
class CommonActionTest extends TestCase
{
    /** @var RequestTokenFactory&MockObject */
    private $requestTokenFactory;

    /** @var CheckoutSession&MockObject */
    private $checkoutSession;

    /** @var OrderFinalizer&MockObject */
    private $orderFinalizer;

    /** @var OrderReconciler&MockObject */
    private $orderReconciler;

    /** @var ManagerInterface&MockObject */
    private $messageManager;

    /** @var RedirectInterface&MockObject */
    private $redirect;

    /** @var OrderFactory&MockObject */
    private $orderFactory;

    /** @var Success */
    private $controller;

    protected function setUp(): void
    {
        $this->requestTokenFactory = $this->getMockBuilder(RequestTokenFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['restoreQuote', 'setLoadInactive'])
            ->getMock();
        $this->orderFinalizer = $this->createMock(OrderFinalizer::class);
        $this->orderReconciler = $this->createMock(OrderReconciler::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->redirect = $this->createMock(RedirectInterface::class);
        $this->orderFactory = $this->createMock(OrderFactory::class);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->createMock(RequestInterface::class));
        $context->method('getResponse')->willReturn($this->createMock(ResponseInterface::class));
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getRedirect')->willReturn($this->redirect);

        $this->controller = new Success(
            $context,
            $this->createMock(PayflexLogger::class),
            $this->checkoutSession,
            $this->createMock(Configuration::class),
            $this->orderFinalizer,
            $this->orderReconciler,
            $this->requestTokenFactory,
            $this->orderFactory
        );
    }

    /**
     * Payflex took the payment but the order needs review, so the customer is
     * told so and the cart is not restored for a second payment.
     */
    public function testCancelReturnOnAPaidOrderThatNeedsReviewKeepsTheCartEmpty(): void
    {
        $this->orderFinalizer->method('isFinalized')->willReturn(false);
        $this->orderReconciler->method('reconcile')->willReturn(OrderFinalizer::RESULT_NEEDS_REVIEW);
        $this->reloadedOrder($this->order(false));

        $this->expectReviewMessage();

        $this->invoke('_handleCancelReturn', $this->order(false), 'pf-1');
    }

    /**
     * After reconciling, the order is read again so that the comment is saved
     * on the captured order rather than on the copy loaded before the lock.
     */
    public function testCancelReturnOnACapturedOrderCommentsOnTheReloadedOrder(): void
    {
        $this->orderFinalizer->method('isFinalized')->willReturn(false);
        $this->orderReconciler->method('reconcile')->willReturn(OrderFinalizer::RESULT_CAPTURED);

        $staleOrder = $this->order(false);
        $staleOrder->expects($this->once())->method('addStatusHistoryComment')
            ->with($this->stringContains('cancel link'));

        $freshOrder = $this->reloadedOrder($this->order(false));
        $freshOrder->expects($this->once())->method('addStatusHistoryComment')
            ->with($this->stringContains('already been approved'));

        $this->checkoutSession->expects($this->never())->method('restoreQuote');
        $this->redirect->expects($this->once())->method('redirect')
            ->with($this->anything(), 'checkout/onepage/success');

        $this->invoke('_handleCancelReturn', $staleOrder, 'pf-1');
    }

    public function testCancelReturnOnASuspectedFraudOrderKeepsTheCartEmpty(): void
    {
        $this->orderFinalizer->method('isFinalized')->willReturn(true);
        $this->orderReconciler->expects($this->never())->method('reconcile');

        $this->expectReviewMessage();

        $this->invoke('_handleCancelReturn', $this->order(true), 'pf-1');
    }

    public function testSuccessReturnOnASuspectedFraudOrderShowsTheReviewMessage(): void
    {
        $this->orderFinalizer->method('isFinalized')->willReturn(true);
        $this->orderReconciler->expects($this->never())->method('reconcile');

        $this->expectReviewMessage();

        $this->invoke('_handleSuccessReturn', $this->order(true), 'pf-1');
    }

    public function testValidateTokenAcceptsAnyAttemptForTheOrder(): void
    {
        $this->storedAttempt('pf-old', 'token-old');

        $this->assertTrue($this->validateToken('pf-old', 'token-old', '100000001'));
    }

    public function testValidateTokenRejectsAMismatchedToken(): void
    {
        $this->storedAttempt('pf-old', 'token-old');

        $this->assertFalse($this->validateToken('pf-old', 'token-new', '100000001'));
    }

    public function testValidateTokenRejectsAnAttemptForAnotherOrder(): void
    {
        $this->storedAttempt('pf-old', 'token-old');

        $this->assertFalse($this->validateToken('pf-old', 'token-old', '100000002'));
    }

    private function storedAttempt(string $payflexId, string $token): void
    {
        $requestToken = $this->getMockBuilder(RequestToken::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->getMock();
        $requestToken->method('load')->with($payflexId, 'payflex_id')->willReturnSelf();
        $requestToken->setData([
            'id'         => 1,
            'token'      => $token,
            'order_id'   => '100000001',
            'payflex_id' => $payflexId,
        ]);

        $this->requestTokenFactory->method('create')->willReturn($requestToken);
    }

    private function expectReviewMessage(): void
    {
        $this->checkoutSession->expects($this->never())->method('restoreQuote');
        $this->messageManager->expects($this->once())->method('addErrorMessage')
            ->with($this->stringContains('payment went through'));
        $this->redirect->expects($this->once())->method('redirect')
            ->with($this->anything(), 'checkout/cart');
    }

    private function order(bool $inPaymentReview): Order
    {
        $history = $this->createMock(History::class);
        $history->method('setIsCustomerNotified')->willReturnSelf();

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('isPaymentReview')->willReturn($inPaymentReview);
        $order->method('addStatusHistoryComment')->willReturn($history);

        return $order;
    }

    private function reloadedOrder(Order $order): Order
    {
        $order->method('loadByIncrementId')->with('100000001')->willReturnSelf();
        $this->orderFactory->method('create')->willReturn($order);

        return $order;
    }

    private function invoke(string $methodName, Order $order, string $payflexId): void
    {
        $method = new \ReflectionMethod($this->controller, $methodName);
        $method->invoke($this->controller, $order, $payflexId);
    }

    private function validateToken($payflexId, $orderToken, $orderIncrementId): bool
    {
        $method = new \ReflectionMethod($this->controller, '_validateToken');

        return $method->invoke($this->controller, $payflexId, $orderToken, $orderIncrementId);
    }
}
