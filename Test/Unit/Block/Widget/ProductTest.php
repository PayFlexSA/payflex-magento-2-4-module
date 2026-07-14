<?php

namespace Payflex\Gateway\Test\Unit\Block\Widget;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template\Context;
use Payflex\Gateway\Block\Widget\Product as ProductWidget;
use Payflex\Gateway\Helper\PaymentUtil;
use Payflex\Gateway\Logger\PayflexLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The product-page widget block ({@see ProductWidget}) is the entry point the
 * catalog_product_view layout renders. Its job is small but important: work out
 * *which* product the page is showing and hand that product's price to
 * {@see PaymentUtil::getWidgetHtml()} for the actual markup.
 *
 * These tests cover the two ways it finds the product — the already-registered
 * product (the normal PDP case) and the request-parameter fallback — and prove
 * the block can be instantiated and invoked without touching the network or a
 * database. The markup itself is checked in {@see \Payflex\Gateway\Test\Unit\Helper\PaymentUtilWidgetTest}.
 */
class ProductTest extends TestCase
{
    /** @var Registry&MockObject */
    private $registry;

    /** @var ProductRepositoryInterface&MockObject */
    private $productRepository;

    /** @var PaymentUtil&MockObject */
    private $paymentUtil;

    /** @var RequestInterface&MockObject */
    private $request;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(Registry::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->paymentUtil = $this->createMock(PaymentUtil::class);
        $this->request = $this->createMock(RequestInterface::class);
    }

    private function newBlock(): ProductWidget
    {
        // Template\Context exposes the request (and much else) to the block; a
        // plain mock returns null for everything we don't explicitly wire up,
        // which is all the block needs for these tests.
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);

        return new ProductWidget(
            $context,
            $this->registry,
            $this->productRepository,
            $this->paymentUtil,
            $this->createMock(PayflexLogger::class)
        );
    }

    /**
     * A mock product whose getFinalPrice() returns the given amount. Product
     * extends AbstractModel, so getFinalPrice() is a real declared method.
     */
    private function productWithFinalPrice(float $price): Product
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFinalPrice'])
            ->getMock();
        $product->method('getFinalPrice')->willReturn($price);

        return $product;
    }

    public function testUsesProductAlreadyInRegistry(): void
    {
        $product = $this->productWithFinalPrice(499.0);
        $this->registry->method('registry')->with('product')->willReturn($product);

        // The registry already has the product, so the repository must not be hit.
        $this->productRepository->expects($this->never())->method('getById');

        // The block must pass exactly the product's final price through.
        $this->paymentUtil->expects($this->once())
            ->method('getWidgetHtml')
            ->with(499.0)
            ->willReturn('<script></script>');

        $this->assertSame('<script></script>', $this->newBlock()->getProductWidgetHtml());
    }

    public function testFallsBackToRequestIdWhenRegistryEmpty(): void
    {
        $product = $this->productWithFinalPrice(1200.0);

        // Registry is empty, so the block should load the product by the "id"
        // request parameter and register it for the rest of the page.
        $this->registry->method('registry')->with('product')->willReturn(null);
        $this->request->method('getParam')->with('id')->willReturn('42');
        $this->productRepository->expects($this->once())
            ->method('getById')
            ->with(42)
            ->willReturn($product);
        $this->registry->expects($this->once())->method('register')->with('product', $product);

        $this->paymentUtil->method('getWidgetHtml')->with(1200.0)->willReturn('<script></script>');

        $this->assertSame('<script></script>', $this->newBlock()->getProductWidgetHtml());
    }

    public function testHonoursAnExplicitlyPassedProduct(): void
    {
        $product = $this->productWithFinalPrice(75.5);

        // When a product is passed in directly, neither the registry nor the
        // repository should be consulted at all.
        $this->registry->expects($this->never())->method('registry');
        $this->productRepository->expects($this->never())->method('getById');

        $this->paymentUtil->method('getWidgetHtml')->with(75.5)->willReturn('<script></script>');

        $this->assertSame('<script></script>', $this->newBlock()->getProductWidgetHtml($product));
    }
}
