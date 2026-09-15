<?php

namespace Payflex\Gateway\Test\Unit\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\DataObject\Factory as DataObjectFactory;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Helper\PaymentUtil;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\Configuration as MerchantConfiguration;
use Payflex\Gateway\Model\ConfigurationFactory;
use Payflex\Gateway\Service\OrderReconciler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * PaymentUtil::getWidgetHtml() builds the Payflex "calculator" widget that the
 * product-page and cart blocks embed. This is the piece that actually ends up
 * in the storefront HTML, so these tests focus on two things:
 *
 *   1. The gating — the widget only appears when the payment method is enabled,
 *      the product-widget toggle is on, and the merchant's min/max spend limits
 *      are known. Otherwise it must return false/void and add nothing to the page.
 *   2. The markup — when it does render, it must be a single, valid, self-closed
 *      <script> tag pointing at an https Payflex URL. Malformed markup or a
 *      broken src is exactly what would throw a console/JS error on the PDP, so
 *      we parse the output with DOMDocument and assert it is well-formed.
 *
 * Everything is mocked, so no HTTP call or database query is made.
 */
class PaymentUtilWidgetTest extends TestCase
{
    /** @var Configuration&MockObject */
    private $configHelper;

    /** @var ConfigurationFactory&MockObject */
    private $configurationFactory;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    protected function setUp(): void
    {
        $this->configHelper = $this->createMock(Configuration::class);
        $this->configurationFactory = $this->createMock(ConfigurationFactory::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        // Store id 1 for every test; the exact value doesn't matter here.
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);
    }

    private function newPaymentUtil(): PaymentUtil
    {
        return new PaymentUtil(
            $this->createMock(Context::class),
            $this->createMock(PayflexLogger::class),
            $this->createMock(Communication::class),
            $this->configHelper,
            $this->storeManager,
            $this->createMock(OrderCollectionFactory::class),
            $this->createMock(QuoteFactory::class),
            $this->createMock(OrderFactory::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(DataObjectFactory::class),
            $this->configurationFactory,
            $this->createMock(OrderReconciler::class)
        );
    }

    /**
     * A merchant-configuration model with a known id and the given spend limits.
     * getMin()/getMax() are magic AbstractModel getters, hence addMethods();
     * getId()/load() are real declared methods, hence onlyMethods().
     */
    private function merchantConfig($min, $max): MerchantConfiguration
    {
        $model = $this->getMockBuilder(MerchantConfiguration::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'load'])
            ->addMethods(['getMin', 'getMax'])
            ->getMock();
        $model->method('load')->willReturnSelf();
        $model->method('getId')->willReturn(1); // already persisted -> no API refresh
        $model->method('getMin')->willReturn($min);
        $model->method('getMax')->willReturn($max);
        $this->configurationFactory->method('create')->willReturn($model);

        return $model;
    }

    public function testReturnsFalseWhenPaymentMethodDisabled(): void
    {
        $this->configHelper->method('getEnabled')->willReturn(false);

        $this->assertFalse($this->newPaymentUtil()->getWidgetHtml(100.0));
    }

    public function testReturnsFalseWhenMerchantHasNoSpendLimits(): void
    {
        $this->configHelper->method('getEnabled')->willReturn(true);
        // No min/max known for this merchant yet -> nothing to show.
        $this->merchantConfig(null, null);

        $this->assertFalse($this->newPaymentUtil()->getWidgetHtml(100.0));
    }

    public function testRendersNothingWhenProductWidgetToggleIsOff(): void
    {
        $this->configHelper->method('getEnabled')->willReturn(true);
        $this->configHelper->method('getProductWidget')->willReturn(false);
        $this->merchantConfig(50, 20000);

        // getWidgetHtml() returns void (null) in this branch: limits are set but
        // the store has switched the product widget off.
        $this->assertNull($this->newPaymentUtil()->getWidgetHtml(100.0));
    }

    public function testRendersValidScriptTagWhenEnabled(): void
    {
        $this->configHelper->method('getEnabled')->willReturn(true);
        $this->configHelper->method('getProductWidget')->willReturn(true);
        $this->configHelper->method('getMerchantName')->willReturn('Acme Store');
        $this->merchantConfig(50, 20000);

        $html = $this->newPaymentUtil()->getWidgetHtml(1234.56);

        $this->assertIsString($html);
        $script = $this->assertSingleWellFormedScript($html);

        $src = $script->getAttribute('src');
        // A valid, absolute https URL keeps the browser from complaining and
        // means the async script actually loads.
        $this->assertNotFalse(filter_var($src, FILTER_VALIDATE_URL), "src is not a valid URL: {$src}");
        $this->assertStringStartsWith('https://widgets.payflex.co.za/', $src);
        // The spend limits and cart amount must be carried through as query args.
        $this->assertStringContainsString('min=50', $src);
        $this->assertStringContainsString('max=20000', $src);
        $this->assertStringContainsString('amount=1234.56', $src);
        // Spaces in the merchant name must be URL-safe, never a raw space that
        // would break the tag.
        $this->assertStringNotContainsString(' ', $src);
        $this->assertSame('application/javascript', $script->getAttribute('type'));
    }

    public function testMerchantNameWithSpacesIsSluggedIntoTheUrl(): void
    {
        $this->configHelper->method('getEnabled')->willReturn(true);
        $this->configHelper->method('getProductWidget')->willReturn(true);
        $this->configHelper->method('getMerchantName')->willReturn('Acme Store');
        $this->merchantConfig(50, 20000);

        $html = $this->newPaymentUtil()->getWidgetHtml(100.0);
        $src = $this->assertSingleWellFormedScript($html)->getAttribute('src');

        // Spaces become hyphens and the whole segment is URL-encoded, so the
        // merchant slug appears as "Acme-Store" in the path.
        $this->assertStringContainsString('/Acme-Store/', $src);
    }

    public function testUsesGenericUrlWhenMerchantNameMissing(): void
    {
        $this->configHelper->method('getEnabled')->willReturn(true);
        $this->configHelper->method('getProductWidget')->willReturn(true);
        $this->configHelper->method('getMerchantName')->willReturn(''); // not configured
        $this->merchantConfig(50, 20000);

        $html = $this->newPaymentUtil()->getWidgetHtml(100.0);

        $script = $this->assertSingleWellFormedScript($html);
        // Without a merchant name the URL must NOT contain the placeholder.
        $this->assertStringNotContainsString('yourmerchantname', $script->getAttribute('src'));
        $this->assertNotFalse(filter_var($script->getAttribute('src'), FILTER_VALIDATE_URL));
    }

    /**
     * Parse the markup and assert it is exactly one well-formed <script> element.
     * Loading it through DOMDocument in strict mode is a cheap proxy for "this
     * won't blow up the page": malformed or unbalanced markup raises a warning
     * that libxml records, which we surface as a failure.
     */
    private function assertSingleWellFormedScript(string $html): \DOMElement
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new \DOMDocument();
        // Wrap in a body so a bare <script> fragment is valid to parse.
        $doc->loadHTML('<body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);

        // Code 23 (htmlParseEntityRef) fires on the raw "&" that separates URL
        // query args. Real browsers accept a bare "&" in an attribute value, so
        // that warning is benign here; any *other* libxml error means genuinely
        // broken markup (unbalanced/unclosed tags) and should fail the test.
        $errors = array_filter(
            libxml_get_errors(),
            static fn (\LibXMLError $e): bool => $e->code !== 23
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertSame([], $errors, 'Widget markup is not well-formed HTML.');

        $scripts = $doc->getElementsByTagName('script');
        $this->assertSame(1, $scripts->length, 'Expected exactly one <script> tag.');

        return $scripts->item(0);
    }
}
