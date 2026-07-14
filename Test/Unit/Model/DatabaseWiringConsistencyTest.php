<?php
declare(strict_types=1);

namespace Payflex\Gateway\Test\Unit\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Checks the model / resource-model / collection triads that make up the
 * module's persistence layer are wired to each other consistently, and that
 * each class autoloads (i.e. its declared namespace matches its file path per
 * PSR-4). A collection whose namespace does not match its path, or that binds
 * to the wrong resource model, silently fails to load or reads the wrong table.
 */
class DatabaseWiringConsistencyTest extends TestCase
{
    /**
     * Each persisted entity and the three classes that implement it.
     */
    private const ENTITIES = [
        'CheckOrderStatus' => [
            'model'      => \Payflex\Gateway\Model\CheckOrderStatus::class,
            'resource'   => \Payflex\Gateway\Model\ResourceModel\CheckOrderStatus::class,
            'collection' => \Payflex\Gateway\Model\ResourceModel\CheckOrderStatus\Collection::class,
        ],
        'Configuration' => [
            'model'      => \Payflex\Gateway\Model\Configuration::class,
            'resource'   => \Payflex\Gateway\Model\ResourceModel\Configuration::class,
            'collection' => \Payflex\Gateway\Model\ResourceModel\Configuration\Collection::class,
        ],
        'RequestAccessToken' => [
            'model'      => \Payflex\Gateway\Model\RequestAccessToken::class,
            'resource'   => \Payflex\Gateway\Model\ResourceModel\RequestAccessToken::class,
            'collection' => \Payflex\Gateway\Model\ResourceModel\RequestAccessToken\Collection::class,
        ],
        'RequestToken' => [
            'model'      => \Payflex\Gateway\Model\RequestToken::class,
            'resource'   => \Payflex\Gateway\Model\ResourceModel\RequestToken::class,
            'collection' => \Payflex\Gateway\Model\ResourceModel\RequestToken\Collection::class,
        ],
    ];

    /**
     * @dataProvider entityProvider
     */
    public function testAllThreeClassesExistAndAutoload(string $entity, array $classes): void
    {
        // class_exists() triggers the autoloader; it returns false for a class
        // whose file path does not match its namespace (a PSR-4 violation).
        self::assertTrue(class_exists($classes['model']), "{$classes['model']} does not exist / autoload.");
        self::assertTrue(class_exists($classes['resource']), "{$classes['resource']} does not exist / autoload.");
        self::assertTrue(class_exists($classes['collection']), "{$classes['collection']} does not exist / autoload.");
    }

    /**
     * @dataProvider entityProvider
     */
    public function testClassesExtendTheExpectedMagentoBaseClasses(string $entity, array $classes): void
    {
        self::assertTrue(is_subclass_of($classes['model'], AbstractModel::class), "{$classes['model']} must extend AbstractModel.");
        self::assertTrue(is_subclass_of($classes['resource'], AbstractDb::class), "{$classes['resource']} must extend AbstractDb.");
        self::assertTrue(is_subclass_of($classes['collection'], AbstractCollection::class), "{$classes['collection']} must extend AbstractCollection.");
    }

    /**
     * Each class file's declared namespace + class name must map back to its
     * own file path under the module's PSR-4 root (Payflex\Gateway\ => module
     * root). This is exactly the invariant a namespace-in-the-wrong-directory
     * bug violates.
     *
     * @dataProvider entityProvider
     */
    public function testClassFilesSatisfyPsr4(string $entity, array $classes): void
    {
        foreach ($classes as $role => $class) {
            $expectedFile = $this->psr4PathFor($class);
            $actualFile   = (new ReflectionClass($class))->getFileName();

            self::assertSame(
                $expectedFile,
                $actualFile,
                "{$role} class {$class} is defined in a file that does not match its namespace (PSR-4 violation)."
            );
        }
    }

    /**
     * The model must bind to its own resource model, and the collection must
     * bind to that same model + resource-model pair. Mismatched wiring means a
     * collection reads a different table than its items save to.
     *
     * @dataProvider entityProvider
     */
    public function testModelResourceAndCollectionWiringAgree(string $entity, array $classes): void
    {
        $modelInit = $this->initArguments($classes['model']);
        self::assertCount(1, $modelInit, "{$classes['model']}::_construct() must call _init() with the resource model.");
        self::assertSame(
            ltrim($classes['resource'], '\\'),
            $modelInit[0],
            "{$classes['model']} must bind to its own resource model."
        );

        $collectionInit = $this->initArguments($classes['collection']);
        self::assertCount(2, $collectionInit, "{$classes['collection']}::_construct() must call _init(model, resource).");
        self::assertSame(
            ltrim($classes['model'], '\\'),
            $collectionInit[0],
            "{$classes['collection']} must bind to the {$entity} model."
        );
        self::assertSame(
            ltrim($classes['resource'], '\\'),
            $collectionInit[1],
            "{$classes['collection']} must bind to the {$entity} resource model."
        );

        // The classes named in the _init() calls must actually exist.
        self::assertTrue(class_exists($collectionInit[0]), "Collection references non-existent model {$collectionInit[0]}.");
        self::assertTrue(class_exists($collectionInit[1]), "Collection references non-existent resource {$collectionInit[1]}.");
    }

    /**
     * Map a fully-qualified class name to the file it must live in, given the
     * module's PSR-4 root of Payflex\Gateway\ => <module root>.
     */
    private function psr4PathFor(string $class): string
    {
        $moduleRoot = dirname((new ReflectionClass(\Payflex\Gateway\Setup\InstallSchema::class))->getFileName(), 2);
        $relative   = substr(ltrim($class, '\\'), strlen('Payflex\\Gateway\\'));
        return $moduleRoot . '/' . str_replace('\\', '/', $relative) . '.php';
    }

    /**
     * Extract the class-name arguments passed to the single _init() call inside
     * a class's _construct(), normalising both "Foo\Bar::class" and quoted
     * "'Foo\\Bar'" forms to a bare, backslash-trimmed FQCN string.
     *
     * @return string[]
     */
    private function initArguments(string $class): array
    {
        $file   = (new ReflectionClass($class))->getFileName();
        $source = (string)file_get_contents($file);

        if (!preg_match('/_init\s*\((.*?)\)\s*;/s', $source, $match)) {
            return [];
        }

        $args = [];
        foreach (explode(',', $match[1]) as $raw) {
            $arg = trim($raw);
            if ($arg === '') {
                continue;
            }
            $arg = preg_replace('/::class$/', '', $arg);   // Foo::class -> Foo
            $arg = trim($arg, "'\" \t\n\r");                // '\Foo\Bar' -> \Foo\Bar
            $arg = ltrim($arg, '\\');                        // \Foo\Bar   -> Foo\Bar
            $args[] = $arg;
        }
        return $args;
    }

    public static function entityProvider(): array
    {
        $cases = [];
        foreach (self::ENTITIES as $entity => $classes) {
            $cases[$entity] = [$entity, $classes];
        }
        return $cases;
    }
}
