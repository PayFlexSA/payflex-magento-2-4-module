<?php
declare(strict_types=1);

namespace Payflex\Gateway\Test\Unit\Setup;

/**
 * A minimal fake DB adapter for the schema consistency tests. It answers the
 * three connection calls the module's Setup scripts make — isTableExists(),
 * newTable() and createTable() — and remembers every table handed to
 * createTable() so a test can assert on the resulting schema.
 *
 * `existingTables` lets a test pretend a table is already present, which is how
 * the "install is idempotent" guard in the Setup scripts is exercised.
 */
class RecordingSchemaConnection
{
    /** @var array<string, RecordingSchemaTable> table name => definition passed to createTable() */
    public $created = [];

    /** @var string[] table names that isTableExists() should report as already present */
    public $existingTables = [];

    /**
     * @param string $tableName
     * @param string|null $schemaName
     * @return bool
     */
    public function isTableExists($tableName, $schemaName = null)
    {
        return in_array($tableName, $this->existingTables, true);
    }

    /**
     * @param string|null $tableName
     * @param string|null $schemaName
     * @return RecordingSchemaTable
     */
    public function newTable($tableName = null, $schemaName = null)
    {
        $table = new RecordingSchemaTable();
        $table->name = (string)$tableName;
        return $table;
    }

    /**
     * @param RecordingSchemaTable $table
     * @return RecordingSchemaTable
     */
    public function createTable($table)
    {
        $this->created[$table->name] = $table;
        return $table;
    }
}
