<?php

use Magento\Framework\Component\ComponentRegistrar;

// Guard against double-registration when the module is present in both
// vendor/ (Composer) and app/code/ (manual). Whichever copy loads first
// wins; the second silently defers instead of throwing a fatal error.
// Note: register() is static, but getPath() is an instance method.
$payflexRegistrar = new ComponentRegistrar();
if (!$payflexRegistrar->getPath(ComponentRegistrar::MODULE, 'Payflex_Gateway')) {
    ComponentRegistrar::register(
        ComponentRegistrar::MODULE,
        'Payflex_Gateway',
        __DIR__
    );
}