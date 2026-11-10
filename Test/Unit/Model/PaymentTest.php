<?php

namespace Payflex\Gateway\Test\Unit\Model;

use Magento\Sales\Model\Order\Payment as OrderPayment;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\Payment;
use PHPUnit\Framework\TestCase;

/**
 * Covers accepting and denying a payment held for review.
 */
class PaymentTest extends TestCase
{
    /** @var Payment */
    private $method;

    /**
     * The payment method without its constructor, which needs the global object manager.
     */
    protected function setUp(): void
    {
        $this->method = (new \ReflectionClass(Payment::class))->newInstanceWithoutConstructor();

        $logger = new \ReflectionProperty(Payment::class, '_logger');
        $logger->setValue($this->method, $this->createMock(PayflexLogger::class));
    }

    public function testAcceptPaymentNeedsNothingFromPayflex(): void
    {
        $this->assertTrue($this->method->acceptPayment($this->payment()));
    }

    /**
     * Denying sends nothing to Payflex, so core's deny comment tells the admin
     * which Payflex payment to refund.
     */
    public function testDenyPaymentAsksForARefundInThePayflexPortal(): void
    {
        $payment = $this->payment();
        $payment->setAdditionalInformation('orderId', 'pf-1');

        $this->assertTrue($this->method->denyPayment($payment));

        $message = (string) $payment->prependMessage('Denied the payment online.');
        $this->assertStringContainsString('refund Payflex Order ID pf-1 in the Payflex portal', $message);
        $this->assertStringEndsWith('Denied the payment online.', $message);
    }

    private function payment(): OrderPayment
    {
        return $this->getMockBuilder(OrderPayment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
    }
}
