<?php
namespace Payflex\Gateway\Logger\Handler;

use \Magento\Framework\Filesystem\DriverInterface;
use \Magento\Framework\Logger\Handler\Base;
use \Monolog\Logger;

class All extends Base
{
    protected $level = Logger::DEBUG;

    public function __construct(DriverInterface $filesystem, $filePath = null)
    {
        $now = new \DateTime('now');
        $strToday = $now->format('Y-m-d');
        $this->fileName = "/var/log/payflex_gateway_{$strToday}.log";
        parent::__construct($filesystem, $filePath);
    }
}
