<?php
declare(strict_types=1);

namespace Payflex\Gateway\Test\Unit\Setup;

use Magento\Framework\Setup\ModuleContextInterface;

/**
 * A ModuleContextInterface whose reported "currently installed" version can be
 * chosen by the test, so UpgradeSchema's version_compare() branches can be
 * driven both ways (fresh install vs. already upgraded).
 */
class RecordingModuleContext implements ModuleContextInterface
{
    /** @var string */
    private $version;

    public function __construct(string $version = '0.0.0')
    {
        $this->version = $version;
    }

    public function getVersion()
    {
        return $this->version;
    }
}
