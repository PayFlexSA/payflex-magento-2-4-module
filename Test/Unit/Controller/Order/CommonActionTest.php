<?php

namespace Payflex\Gateway\Test\Unit\Controller\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\OrderFactory;
use Payflex\Gateway\Controller\Order\Success;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\RequestToken;
use Payflex\Gateway\Model\RequestTokenFactory;
use Payflex\Gateway\Model\ResourceModel\RequestToken as RequestTokenResource;
use Payflex\Gateway\Service\OrderFinalizer;
use Payflex\Gateway\Service\OrderReconciler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the check on the Payflex order id and token the customer returns with.
 */
class CommonActionTest extends TestCase
{
    /** @var RequestTokenFactory&MockObject */
    private $requestTokenFactory;

    /** @var RequestTokenResource&MockObject */
    private $requestTokenResource;

    /** @var Success */
    private $controller;

    protected function setUp(): void
    {
        $this->requestTokenFactory = $this->getMockBuilder(RequestTokenFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->requestTokenResource = $this->createMock(RequestTokenResource::class);

        $this->controller = new Success(
            $this->createMock(Context::class),
            $this->createMock(PayflexLogger::class),
            $this->createMock(CheckoutSession::class),
            $this->createMock(Configuration::class),
            $this->createMock(OrderFinalizer::class),
            $this->createMock(OrderReconciler::class),
            $this->requestTokenFactory,
            $this->createMock(OrderFactory::class),
            $this->requestTokenResource
        );
    }

    public function testValidateTokenAcceptsTheNewestAttempt(): void
    {
        $this->storedAttempt('pf-new', 'token-new');
        $this->requestTokenResource->method('getPayflexIds')->with('100000001')->willReturn(['pf-old', 'pf-new']);

        $this->assertTrue($this->validateToken('pf-new', 'token-new', '100000001'));
    }

    public function testValidateTokenRejectsAnOlderAttempt(): void
    {
        $this->storedAttempt('pf-old', 'token-old');
        $this->requestTokenResource->method('getPayflexIds')->with('100000001')->willReturn(['pf-old', 'pf-new']);

        $this->assertFalse($this->validateToken('pf-old', 'token-old', '100000001'));
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

    private function validateToken($payflexId, $orderToken, $orderIncrementId): bool
    {
        $method = new \ReflectionMethod($this->controller, '_validateToken');

        return $method->invoke($this->controller, $payflexId, $orderToken, $orderIncrementId);
    }
}
