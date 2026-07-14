<?php

namespace Payflex\Gateway\Logger;

use Magento\Framework\App\ProductMetadataInterface;

/**
 * Dedicated Payflex logger (writes to its own daily file via the All handler).
 * Tags every record with the running Magento version for easier support triage.
 */
class PayflexLogger extends \Monolog\Logger
{
    public function __construct(
        $name,
        ProductMetadataInterface $productMetadata,
        array $handlers = [],
        array $processors = []
    ) {
        parent::__construct($name, $handlers, $processors);

        $version = $productMetadata->getVersion();
        $this->pushProcessor(function ($record) use ($version) {
            $record['extra']['magentoVersion'] = $version;
            return $record;
        });
    }
}
