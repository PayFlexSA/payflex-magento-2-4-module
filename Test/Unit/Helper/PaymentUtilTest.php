<?php

namespace Payflex\Gateway\Test\Unit\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\DataObject\Factory as DataObjectFactory;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Helper\PaymentUtil;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\ConfigurationFactory;
use Payflex\Gateway\Model\RequestTokenFactory;
use Payflex\Gateway\Service\OrderFinalizer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The redirect/return flow loads orders and quotes by id before finalising a
 * payment. These loaders must fail closed — returning null on a missing record
 * rather than handing back an empty model that later code would mistake for a
 * real order/quote. The widget markup is covered separately in
 * {@see PaymentUtilWidgetTest}.
 */
class PaymentUtilTest extends TestCase
{
    /** @var OrderFactory&MockObject */
    private $orderFactory;

    /** @var QuoteFactory&MockObject */
    private $quoteFactory;

    protected function setUp(): void
    {
        $this->orderFactory = $this->createMock(OrderFactory::class);
        $this->quoteFactory = $this->createMock(QuoteFactory::class);
    }

    private function newPaymentUtil(): PaymentUtil
    {
        return new PaymentUtil(
            $this->createMock(Context::class),
            $this->createMock(PayflexLogger::class),
            $this->createMock(Communication::class),
            $this->createMock(Configuration::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(OrderCollectionFactory::class),
            $this->quoteFactory,
            $this->orderFactory,
            $this->createMock(UrlInterface::class),
            $this->createMock(DataObjectFactory::class),
            $this->createMock(ConfigurationFactory::class),
            $this->createMock(RequestTokenFactory::class),
            $this->createMock(OrderFinalizer::class)
        );
    }

    public function testLoadOrderByIdReturnsOrderWhenFound(): void
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByAttribute', 'getIncrementId'])
            ->getMock();
        $order->method('loadByAttribute')->with('entity_id', 42)->willReturnSelf();
        $order->method('getIncrementId')->willReturn('100000042');
        $this->orderFactory->method('create')->willReturn($order);

        $this->assertSame($order, $this->newPaymentUtil()->loadOrderById(42));
    }

    public function testLoadOrderByIdReturnsNullWhenIncrementIdMissing(): void
    {
        // A freshly-created, unloaded order has no increment id -> treat as missing.
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByAttribute', 'getIncrementId'])
            ->getMock();
        $order->method('loadByAttribute')->willReturnSelf();
        $order->method('getIncrementId')->willReturn(null);
        $this->orderFactory->method('create')->willReturn($order);

        $this->assertNull($this->newPaymentUtil()->loadOrderById(999));
    }

    public function testLoadQuoteReturnsQuoteWhenFound(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByIdWithoutStore', 'getId'])
            ->getMock();
        $quote->method('loadByIdWithoutStore')->with(7)->willReturnSelf();
        $quote->method('getId')->willReturn(7);
        $this->quoteFactory->method('create')->willReturn($quote);

        $this->assertSame($quote, $this->newPaymentUtil()->loadQuote(7));
    }

    public function testLoadQuoteReturnsNullWhenQuoteMissing(): void
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByIdWithoutStore', 'getId'])
            ->getMock();
        $quote->method('loadByIdWithoutStore')->willReturnSelf();
        $quote->method('getId')->willReturn(null); // nothing loaded
        $this->quoteFactory->method('create')->willReturn($quote);

        $this->assertNull($this->newPaymentUtil()->loadQuote(12345));
    }
}
