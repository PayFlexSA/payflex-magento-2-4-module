<?php

namespace Payflex\Gateway\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Sales\Model\Order;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Logger\PayflexLogger;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    /** @var ScopeConfigInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    /** @var ProductMetadataInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $productMetadata;

    /** @var Configuration */
    private $configuration;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->productMetadata = $this->createMock(ProductMetadataInterface::class);

        $context = $this->createMock(Context::class);
        $context->method('getScopeConfig')->willReturn($this->scopeConfig);

        $this->configuration = new Configuration(
            $context,
            $this->createMock(ModuleListInterface::class),
            $this->productMetadata,
            $this->createMock(PayflexLogger::class)
        );
    }

    /**
     * versionCheck() must compare full semantic versions, not a truncated string.
     * This is the regression guard for the old regex bug (B-1).
     */
    public function testVersionCheckMinimumBoundary(): void
    {
        $this->productMetadata->method('getVersion')->willReturn('2.4.8');

        $this->assertTrue($this->configuration->versionCheck('2.4.6'));
        $this->assertTrue($this->configuration->versionCheck('2.4.8'));
        $this->assertFalse($this->configuration->versionCheck('2.4.9'));
    }

    public function testVersionCheckHandlesPatchSuffix(): void
    {
        $this->productMetadata->method('getVersion')->willReturn('2.4.8-p3');

        $this->assertTrue($this->configuration->versionCheck('2.4.6'));
        $this->assertFalse($this->configuration->versionCheck('2.4.9'));
    }

    public function testVersionCheckRespectsMaximum(): void
    {
        $this->productMetadata->method('getVersion')->willReturn('2.4.4');

        $this->assertTrue($this->configuration->versionCheck('2.4.2', '2.4.6'));
        $this->assertFalse($this->configuration->versionCheck('2.4.2', '2.4.3'));
    }

    /**
     * A two-segment Magento version (e.g. "2.4") is treated by version_compare
     * as "2.4.0", so it sits below any "2.4.x" minimum with x > 0.
     */
    public function testVersionCheckHandlesTwoSegmentVersion(): void
    {
        $this->productMetadata->method('getVersion')->willReturn('2.4');

        $this->assertTrue($this->configuration->versionCheck('2.4'));
        $this->assertTrue($this->configuration->versionCheck('2.3'));
        $this->assertFalse($this->configuration->versionCheck('2.4.6'));
    }

    /**
     * A pre-release build (e.g. a release candidate) sorts *below* its final
     * release, which is exactly how version_compare treats "-rc" suffixes.
     */
    public function testVersionCheckTreatsReleaseCandidateBelowFinal(): void
    {
        $this->productMetadata->method('getVersion')->willReturn('2.4.8-rc1');

        $this->assertTrue($this->configuration->versionCheck('2.4.6'));
        $this->assertFalse($this->configuration->versionCheck('2.4.8'));
    }

    public function testNewOrderStatusFallsBackToProcessing(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame(Order::STATE_PROCESSING, $this->configuration->getPayflexNewOrderStatus());
    }

    public function testNewOrderStatusHonoursConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('complete');

        $this->assertSame('complete', $this->configuration->getPayflexNewOrderStatus());
    }

    public function testGetEnabledCastsToBool(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('1');

        $this->assertTrue($this->configuration->getEnabled());
    }
}
