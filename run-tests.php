<?php
/**
 * Payflex Gateway — standalone unit-test runner.
 *
 * Runs the module's PHPUnit unit tests with a minimal, self-contained PHPUnit
 * configuration so the output is clean and easy to read.
 *
 * Why this script exists:
 *   1. Magento's stock dev/tests/unit/phpunit.xml.dist registers the Allure
 *      reporting extension with a *relative* config path. Depending on the
 *      working directory that either emits a noisy "Config file
 *      allure/allure.config.php doesn't exist" bootstrap warning, or (when it
 *      does resolve) dumps a pile of JSON result files into
 *      dev/tests/unit/var/allure-results. Neither is wanted just to run unit
 *      tests. This runner generates its own config that omits Allure entirely.
 *   2. The module may live in either app/code/Payflex/Gateway (manual install)
 *      or vendor/payflex/magento2-gateway (Composer install). This script
 *      locates itself and the Magento root at runtime, so it works from either
 *      location and from any current working directory.
 *
 * Usage (from anywhere):
 *   php app/code/Payflex/Gateway/run-tests.php
 *   php vendor/payflex/magento2-gateway/run-tests.php
 *
 * Any extra arguments are forwarded to PHPUnit, e.g.:
 *   php .../run-tests.php --filter versionCheck
 *   php .../run-tests.php Test/Unit/Service/OrderFinalizerTest.php
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

/**
 * Walk up the directory tree from the module until we find a Magento root — the
 * directory that contains both Composer's autoloader and Magento's unit-test
 * bootstrap. This resolves correctly whether the module is in app/code or vendor.
 */
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
            return null; // reached the filesystem root
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

// Prefer the real PHPUnit entry point; fall back to the bin/ wrapper.
$phpunit = is_file($phpunitEntry) ? $phpunitEntry : $phpunitBin;

if (!is_file($phpunit)) {
    fwrite(STDERR, "PHPUnit was not found at:\n  {$phpunitEntry}\n  {$phpunitBin}\n\n"
        . "Run Magento's 'composer install' to install the test dependencies.\n");
    exit(1);
}

/**
 * Generate a minimal PHPUnit config on the fly. It reuses Magento's own unit
 * bootstrap (so the framework is initialised exactly as usual) but deliberately
 * omits the <extensions> block, which is where the Allure extension is wired up
 * in the stock config. No Allure => no warning and no result artifacts.
 */
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

// Always remove the temp config, even if PHPUnit or PHP exits unexpectedly.
register_shutdown_function(static function () use ($configFile): void {
    if (is_file($configFile)) {
        @unlink($configFile);
    }
});

// Build the command. Forward any extra CLI args (e.g. --filter, a test path).
//
// Path-like arguments (anything not starting with '-') are resolved against the
// module so a path such as "Test/Unit/Service/OrderFinalizerTest.php" or
// "Service/OrderFinalizerTest.php" works no matter the current directory. If the
// argument doesn't match a module path it is passed through unchanged, so
// option values like the "versionCheck" in "--filter versionCheck" are untouched.
$resolveArg = static function (string $arg) use ($moduleDir, $moduleTests): string {
    if ($arg === '' || $arg[0] === '-') {
        return $arg; // an option/flag or its value — leave it alone
    }
    if (file_exists($arg)) {
        return $arg; // already valid relative to CWD, or absolute
    }
    foreach ([$moduleDir . '/' . $arg, $moduleTests . '/' . $arg] as $candidate) {
        if (file_exists($candidate)) {
            return $candidate;
        }
    }
    return $arg; // not a module path — pass through and let PHPUnit decide
};

$extraArgs = array_slice($argv, 1);

/**
 * Default to PHPUnit's "testdox" reporter, which prints one readable line per
 * test (a plain-English sentence derived from the test method name) so you can
 * see exactly what each test verifies rather than an anonymous row of dots.
 *
 * This is only added when the caller hasn't already chosen how output should be
 * produced — e.g. their own --testdox, any --log-* (such as --log-junit used by
 * the admin Support page), --teamcity or --no-output. That keeps machine-readable
 * runs clean while making the interactive run informative.
 */
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
