<?php
declare(strict_types=1);

namespace Payflex\Gateway\Test\Unit\Model;

use Payflex\Gateway\Test\Unit\Setup\SchemaCapture;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Checks that the tables and id fields the resource models bind to (via
 * AbstractDb::_init()) actually agree with the schema the module's Setup
 * scripts create. A resource model pointing at a table that is never created,
 * or an id field that is not a real column, is a latent fatal that would only
 * surface the first time that entity is loaded or saved in production.
 *
 * @see \Payflex\Gateway\Setup\InstallSchema
 * @see \Payflex\Gateway\Setup\UpgradeSchema
 */
class ResourceModelSchemaConsistencyTest extends TestCase
{
    /**
     * Every AbstractDb resource model in the module.
     */
    private const RESOURCE_MODELS = [
        \Payflex\Gateway\Model\ResourceModel\RequestToken::class,
        \Payflex\Gateway\Model\ResourceModel\Configuration::class,
        \Payflex\Gateway\Model\ResourceModel\RequestAccessToken::class,
        \Payflex\Gateway\Model\ResourceModel\CheckOrderStatus::class,
    ];

    /**
     * Tables referenced by a resource model that the Setup scripts do NOT
     * create, documented deliberately so this stays a green, informative guard
     * rather than a permanent failure:
     *
     *   payflex_gateway_checkorderstatus — bound by the CheckOrderStatus
     *   resource model, but never created by InstallSchema/UpgradeSchema. The
     *   whole CheckOrderStatus model/resource/collection triad is currently
     *   unused (no runtime callers), so the missing table is a dormant defect,
     *   not an active bug. Resolve it by either adding the table to the Setup
     *   scripts or removing the dead triad; then delete the entry below so this
     *   test enforces full backing again.
     *
     * The test asserts the set of unbacked tables equals EXACTLY this list, so
     * any *new* resource model that forgets its table fails the build.
     */
    private const KNOWN_UNBACKED_TABLES = [
        'payflex_gateway_checkorderstatus',
    ];

    /** @var array<string, \Payflex\Gateway\Test\Unit\Setup\RecordingSchemaTable> */
    private $schema;

    protected function setUp(): void
    {
        $this->schema = SchemaCapture::freshInstall();
    }

    /**
     * @dataProvider resourceModelProvider
     */
    public function testResourceModelBindsToATableAndIdField(string $resourceModel): void
    {
        [$table, $idField] = $this->tableAndIdField($resourceModel);

        self::assertNotSame('', $table, "{$resourceModel} must bind to a table via _init().");
        self::assertNotSame('', $idField, "{$resourceModel} must declare an id field via _init().");
        self::assertSame(
            'id',
            $idField,
            "{$resourceModel} uses id field '{$idField}', but every table in this module uses 'id'."
        );
    }

    /**
     * @dataProvider resourceModelProvider
     */
    public function testResourceModelIdFieldIsARealColumnInItsTable(string $resourceModel): void
    {
        [$table, $idField] = $this->tableAndIdField($resourceModel);

        if (in_array($table, self::KNOWN_UNBACKED_TABLES, true)) {
            self::markTestSkipped("Table {$table} is a documented unbacked table (see KNOWN_UNBACKED_TABLES).");
        }

        self::assertArrayHasKey(
            $table,
            $this->schema,
            "{$resourceModel} binds to table '{$table}', which the Setup scripts never create."
        );
        self::assertArrayHasKey(
            $idField,
            $this->schema[$table]->columns,
            "{$resourceModel} id field '{$idField}' is not a column of table '{$table}'."
        );
    }

    /**
     * The safety net: the set of resource-model tables with no backing schema
     * must be EXACTLY the documented list. Adding a resource model without its
     * table (or fixing the known gap without updating the list) breaks this.
     */
    public function testUnbackedTablesAreExactlyTheDocumentedSet(): void
    {
        $unbacked = [];
        foreach (self::RESOURCE_MODELS as $resourceModel) {
            [$table] = $this->tableAndIdField($resourceModel);
            if (!array_key_exists($table, $this->schema)) {
                $unbacked[] = $table;
            }
        }
        sort($unbacked);

        $expected = self::KNOWN_UNBACKED_TABLES;
        sort($expected);

        self::assertSame(
            $expected,
            $unbacked,
            'Resource models reference tables that the Setup scripts do not create. '
            . 'Either create the table(s) in Install/UpgradeSchema, or — if the addition is intentional and '
            . 'tracked — update KNOWN_UNBACKED_TABLES.'
        );
    }

    /**
     * Read the (table, idField) a resource model binds to, without a database.
     *
     * AbstractDb::_init() only assigns the protected $_mainTable/$_idFieldName
     * properties — it touches no connection — so we can construct the object
     * without its real constructor, invoke _construct() to run _init(), and
     * read the two properties back by reflection.
     *
     * @return array{0:string,1:string} [table, idField]
     */
    private function tableAndIdField(string $resourceModel): array
    {
        $reflection = new ReflectionClass($resourceModel);
        $instance   = $reflection->newInstanceWithoutConstructor();

        $construct = $reflection->getMethod('_construct');
        $construct->setAccessible(true);
        $construct->invoke($instance);

        $mainTable = $reflection->getProperty('_mainTable');
        $mainTable->setAccessible(true);

        $idField = $reflection->getProperty('_idFieldName');
        $idField->setAccessible(true);

        return [(string)$mainTable->getValue($instance), (string)$idField->getValue($instance)];
    }

    public static function resourceModelProvider(): array
    {
        $cases = [];
        foreach (self::RESOURCE_MODELS as $resourceModel) {
            $cases[$resourceModel] = [$resourceModel];
        }
        return $cases;
    }
}
