<?php

namespace Payflex\Gateway\Test\Unit\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Helper\PaymentUtil;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\PaymentHelper;
use Payflex\Gateway\Model\ResourceModel\RequestToken as RequestTokenResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers which Payflex order id a credit memo refunds.
 */
class PaymentHelperTest extends TestCase
{
    /** @var Communication&MockObject */
    private $communication;

    /** @var RequestTokenResource&MockObject */
    private $requestTokenResource;

    /** @var PaymentHelper */
    private $paymentHelper;

    protected function setUp(): void
    {
        $this->communication = $this->createMock(Communication::class);
        $this->requestTokenResource = $this->createMock(RequestTokenResource::class);

        $this->paymentHelper = new PaymentHelper(
            $this->createMock(PayflexLogger::class),
            $this->createMock(PaymentUtil::class),
            $this->communication,
            $this->createMock(Configuration::class),
            $this->requestTokenResource
        );
    }

    public function testRefundUsesTheCaptureTransactionOfTheInvoice(): void
    {
        $payment = $this->payment();
        $payment->setRefundTransactionId('pf-paid');
        $payment->setAdditionalInformation('orderId', 'pf-other');

        $this->expectRefundOf('pf-paid');

        $this->paymentHelper->refund($payment, 50.0, 1);
    }

    public function testRefundFallsBackToThePaidIdOnThePayment(): void
    {
        $payment = $this->payment();
        $payment->setAdditionalInformation('orderId', 'pf-paid');

        $this->expectRefundOf('pf-paid');

        $this->paymentHelper->refund($payment, 50.0, 1);
    }

    public function testRefundFallsBackToTheNewestAttempt(): void
    {
        $this->requestTokenResource->method('getPayflexIds')->with('100000001')->willReturn(['pf-old', 'pf-new']);

        $this->expectRefundOf('pf-new');

        $this->paymentHelper->refund($this->payment(), 50.0, 1);
    }

    private function expectRefundOf(string $payflexId): void
    {
        $this->communication->expects($this->once())
            ->method('refund')
            ->with('100000001', $payflexId, 50.0, 1)
            ->willReturn(['response' => '{}', 'errmsg' => '']);
    }

    private function payment(): Payment
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100000001');

        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder'])
            ->getMock();
        $payment->method('getOrder')->willReturn($order);

        return $payment;
    }
}
