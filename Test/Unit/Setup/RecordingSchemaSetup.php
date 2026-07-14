<?php
declare(strict_types=1);

namespace Payflex\Gateway\Test\Unit\Setup;

use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * A real SchemaSetupInterface implementation (so it satisfies the type hint on
 * InstallSchema::install()/UpgradeSchema::upgrade()) that forwards the handful
 * of calls the module's Setup scripts make to a {@see RecordingSchemaConnection}.
 *
 * getTable() returns the logical name unchanged — the module's real DB prefix
 * is irrelevant to the consistency checks, which compare the logical table
 * names declared in the Setup scripts against the logical names the resource
 * models pass to _init().
 */
class RecordingSchemaSetup implements SchemaSetupInterface
{
    /** @var RecordingSchemaConnection */
    private $connection;

    public function __construct(RecordingSchemaConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return RecordingSchemaConnection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    public function setTable($tableName, $realTableName)
    {
        return $this;
    }

    public function getTable($tableName)
    {
        return $tableName;
    }

    public function getTablePlaceholder($tableName)
    {
        return $tableName;
    }

    public function tableExists($table)
    {
        return $this->connection->isTableExists($table);
    }

    public function run($sql)
    {
        return $this;
    }

    public function startSetup()
    {
        return $this;
    }

    public function endSetup()
    {
        return $this;
    }

    public function getIdxName($tableName, $fields, $indexType = '')
    {
        return 'idx';
    }

    public function getFkName($priTableName, $priColumnName, $refTableName, $refColumnName)
    {
        return 'fk';
    }
}
