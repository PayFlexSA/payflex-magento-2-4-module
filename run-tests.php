<?php
/**
 * Runs the module's unit tests without Magento's Allure extension, from either
 * app/code or vendor. Extra arguments are passed to PHPUnit.
 *
 * @copyright Payflex
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$moduleDir   = __DIR__;
$moduleTests = $moduleDir . '/Test/Unit';

if (!is_dir($moduleTests)) {
    fwrite(STDERR, "Could not find the module test directory: {$moduleTests}\n");
    exit(1);
}

$findMagentoRoot = static function (string $start): ?string {
    $dir = $start;
    while (true) {
        if (is_file($dir . '/vendor/autoload.php')
            && is_file($dir . '/dev/tests/unit/framework/bootstrap.php')
        ) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            return null;
        }
        $dir = $parent;
    }
};

$magentoRoot = $findMagentoRoot($moduleDir);

if ($magentoRoot === null) {
    fwrite(STDERR, "Could not locate the Magento root above:\n  {$moduleDir}\n\n"
        . "Expected to find both 'vendor/autoload.php' and\n"
        . "'dev/tests/unit/framework/bootstrap.php' in a parent directory.\n"
        . "Run Magento's 'composer install' first so the test dependencies exist.\n");
    exit(1);
}

$bootstrap    = $magentoRoot . '/dev/tests/unit/framework/bootstrap.php';
$phpunitEntry = $magentoRoot . '/vendor/phpunit/phpunit/phpunit';
$phpunitBin   = $magentoRoot . '/vendor/bin/phpunit';

$phpunit = is_file($phpunitEntry) ? $phpunitEntry : $phpunitBin;

if (!is_file($phpunit)) {
    fwrite(STDERR, "PHPUnit was not found at:\n  {$phpunitEntry}\n  {$phpunitBin}\n\n"
        . "Run Magento's 'composer install' to install the test dependencies.\n");
    exit(1);
}

$configXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         colors="true"
         columns="max"
         beStrictAboutTestsThatDoNotTestAnything="false"
         bootstrap="{$bootstrap}">
    <testsuites>
        <testsuite name="Payflex_Gateway_Unit">
            <directory>{$moduleTests}</directory>
        </testsuite>
    </testsuites>
    <php>
        <ini name="memory_limit" value="-1"/>
        <ini name="date.timezone" value="America/Los_Angeles"/>
        <ini name="xdebug.max_nesting_level" value="200"/>
    </php>
</phpunit>
XML;

$configFile = tempnam(sys_get_temp_dir(), 'payflex-phpunit-') ?: null;
if ($configFile === null || file_put_contents($configFile, $configXml) === false) {
    fwrite(STDERR, "Could not write a temporary PHPUnit config file.\n");
    exit(1);
}

register_shutdown_function(static function () use ($configFile): void {
    if (is_file($configFile)) {
        @unlink($configFile);
    }
});

// Test paths may be given relative to the module or its Test/Unit folder.
$resolveArg = static function (string $arg) use ($moduleDir, $moduleTests): string {
    if ($arg === '' || $arg[0] === '-') {
        return $arg;
    }
    if (file_exists($arg)) {
        return $arg;
    }
    foreach ([$moduleDir . '/' . $arg, $moduleTests . '/' . $arg] as $candidate) {
        if (file_exists($candidate)) {
            return $candidate;
        }
    }
    return $arg;
};

$extraArgs = array_slice($argv, 1);

// Default to --testdox unless the caller picked an output format.
$controlsOutput = static function (array $args): bool {
    foreach ($args as $a) {
        if (strncmp($a, '--testdox', 9) === 0
            || strncmp($a, '--log-', 6) === 0
            || strncmp($a, '--teamcity', 10) === 0
            || $a === '--no-output'
        ) {
            return true;
        }
    }
    return false;
};

$autoArgs = $controlsOutput($extraArgs) ? [] : ['--testdox'];

$command = escapeshellarg(PHP_BINARY)
    . ' -d memory_limit=-1 '
    . escapeshellarg($phpunit)
    . ' -c ' . escapeshellarg($configFile);

foreach (array_merge($autoArgs, $extraArgs) as $arg) {
    $command .= ' ' . escapeshellarg($resolveArg($arg));
}

fwrite(STDOUT, "Payflex Gateway unit tests\n");
fwrite(STDOUT, "Magento root: {$magentoRoot}\n");
fwrite(STDOUT, "Module:       {$moduleDir}\n\n");

$exitCode = 0;
passthru($command, $exitCode);

exit($exitCode);
