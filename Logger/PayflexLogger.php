<?php

namespace Payflex\Gateway\Logger;

// Refer to vendor\monolog\monolog\src\Monolog\Logger.php
// Log to separate file
class PayflexLogger extends \Monolog\Logger
{

    public function __construct($name, array $handlers = [], array $processors = [])
    {
        parent::__construct($name, $handlers, $processors);

        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $productMetadata = $objectManager->get('\Magento\Framework\App\ProductMetadataInterface');
            $version = $productMetadata->getVersion();
            $this->pushProcessor(function ($record) use ($version) {
                $record['extra']['magentoVersion'] = $version;
                return $record;
            });
        } catch (\Exception $e) {
            print 'Caught exception: ' . $e->getMessage() . "\n";
        }
    }
}
