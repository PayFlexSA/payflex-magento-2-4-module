<?php
declare(strict_types=1);

namespace Payflex\Gateway\Test\Unit\Setup;

use Magento\Framework\DB\Ddl\Table;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the database schema the module declares in its Setup scripts is
 * internally consistent: the expected tables are created, each has a sane
 * primary key, columns have the expected types, and the "don't recreate an
 * existing table" / "don't re-run an applied upgrade" guards hold.
 *
 * @see \Payflex\Gateway\Setup\InstallSchema
 * @see \Payflex\Gateway\Setup\UpgradeSchema
 */
class SchemaConsistencyTest extends TestCase
{
    /**
     * The complete set of tables a fresh install should create, with the
     * columns each is expected to have. Kept here as the single source of
     * truth the Setup scripts are checked against.
     */
    private const EXPECTED_TABLES = [
        'payflex_gateway' => [
            'columns' => ['id', 'token', 'url', 'expire', 'order_id', 'payflex_id'],
            'indexes' => ['token', 'order_id', 'payflex_id'],
        ],
        'payflex_gateway_configuration' => [
            'columns' => ['id', 'store_id', 'min', 'max'],
            'indexes' => [],
        ],
        'payflex_gateway_accessToken' => [
            'columns' => ['id', 'store_id', 'token', 'expire'],
            'indexes' => [],
        ],
    ];

    /** @var RecordingSchemaTable[] */
    private $schema;

    protected function setUp(): void
    {
        $this->schema = SchemaCapture::freshInstall();
    }

    public function testFreshInstallCreatesExactlyTheExpectedTables(): void
    {
        $actual   = array_keys($this->schema);
        $expected = array_keys(self::EXPECTED_TABLES);
        sort($actual);
        sort($expected);

        self::assertSame(
            $expected,
            $actual,
            'The set of tables created by the Setup scripts has drifted from what the tests expect. '
            . 'If a table was intentionally added or removed, update SchemaConsistencyTest::EXPECTED_TABLES.'
        );
    }

    /**
     * @dataProvider tableProvider
     */
    public function testTableDeclaresExpectedColumns(string $table, array $spec): void
    {
        self::assertArrayHasKey($table, $this->schema, "Table {$table} was not created.");

        $actual = array_keys($this->schema[$table]->columns);
        sort($actual);
        $expected = $spec['columns'];
        sort($expected);

        self::assertSame($expected, $actual, "Columns for {$table} do not match the expected definition.");
    }

    /**
     * @dataProvider tableProvider
     */
    public function testTableDeclaresExpectedIndexes(string $table, array $spec): void
    {
        self::assertArrayHasKey($table, $this->schema, "Table {$table} was not created.");

        $actual = array_keys($this->schema[$table]->indexes);
        sort($actual);
        $expected = $spec['indexes'];
        sort($expected);

        self::assertSame($expected, $actual, "Indexes for {$table} do not match the expected definition.");
    }

    /**
     * Every table must have exactly one primary-key column, and by this
     * module's convention it must be an unsigned, non-nullable, auto-increment
     * integer named "id" — which is also the id field every resource model
     * passes to _init(). A drift here would silently break persistence.
     *
     * @dataProvider tableProvider
     */
    public function testEveryTableHasASingleAutoIncrementPrimaryKeyNamedId(string $table): void
    {
        self::assertArrayHasKey($table, $this->schema, "Table {$table} was not created.");

        $primaryColumns = [];
        foreach ($this->schema[$table]->columns as $name => $definition) {
            if (!empty($definition['options']['primary'])) {
                $primaryColumns[$name] = $definition;
            }
        }

        self::assertCount(
            1,
            $primaryColumns,
            "Table {$table} must declare exactly one primary-key column; found: " . implode(', ', array_keys($primaryColumns))
        );

        self::assertArrayHasKey('id', $primaryColumns, "Table {$table} primary key must be the 'id' column.");

        $id = $primaryColumns['id'];
        self::assertSame(Table::TYPE_INTEGER, $id['type'], "{$table}.id must be an integer column.");
        self::assertTrue((bool)($id['options']['identity'] ?? false), "{$table}.id must be auto-increment (identity).");
        self::assertTrue((bool)($id['options']['unsigned'] ?? false), "{$table}.id must be unsigned.");
        self::assertFalse((bool)($id['options']['nullable'] ?? true), "{$table}.id must be NOT NULL.");
    }

    public function testInstallSkipsTablesThatAlreadyExist(): void
    {
        $connection = SchemaCapture::install(
            ['payflex_gateway', 'payflex_gateway_configuration']
        );

        self::assertSame(
            [],
            array_keys($connection->created),
            'InstallSchema must not recreate tables that already exist.'
        );
    }

    public function testInstallCreatesBothTablesOnAFreshDatabase(): void
    {
        $connection = SchemaCapture::install([]);

        $created = array_keys($connection->created);
        sort($created);

        self::assertSame(['payflex_gateway', 'payflex_gateway_configuration'], $created);
    }

    public function testUpgradeAddsAccessTokenTableForOldInstalls(): void
    {
        $connection = SchemaCapture::upgradeFrom('1.0.0');

        self::assertArrayHasKey(
            'payflex_gateway_accessToken',
            $connection->created,
            'Installs older than 1.0.1 must have the access-token table created on upgrade.'
        );
    }

    public function testUpgradeIsNotReappliedOnceTheVersionHasBeenReached(): void
    {
        $connection = SchemaCapture::upgradeFrom('1.0.1');

        self::assertSame(
            [],
            array_keys($connection->created),
            'UpgradeSchema must not re-create the access-token table once version 1.0.1 has been applied.'
        );
    }

    /**
     * The access-token column is widened to 2M (MEDIUMTEXT) precisely because a
     * JWT will not fit in 255 chars; guard that intent so a future edit can't
     * quietly shrink it back and start truncating tokens.
     */
    public function testAccessTokenColumnIsLargeEnoughForAJwt(): void
    {
        self::assertArrayHasKey('payflex_gateway_accessToken', $this->schema);

        $tokenColumn = $this->schema['payflex_gateway_accessToken']->columns['token'] ?? null;
        self::assertNotNull($tokenColumn, 'access-token table must have a token column.');
        self::assertSame('2M', $tokenColumn['size'], 'The access token column must stay MEDIUMTEXT (2M).');
    }

    public static function tableProvider(): array
    {
        $cases = [];
        foreach (self::EXPECTED_TABLES as $table => $spec) {
            $cases[$table] = [$table, $spec];
        }
        return $cases;
    }
}
