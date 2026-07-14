<?php
declare(strict_types=1);

namespace Payflex\Gateway\Test\Unit\Setup;

use Payflex\Gateway\Setup\InstallSchema;
use Payflex\Gateway\Setup\UpgradeSchema;

/**
 * Runs the module's InstallSchema and UpgradeSchema against the recording
 * fakes and returns the resulting table definitions, so the consistency tests
 * can reason about the schema the module actually declares (as opposed to a
 * hand-maintained duplicate that could drift out of sync).
 *
 * Because it executes the real Setup code paths, these tests double as a guard
 * that the Setup scripts stay runnable (no fatal from an API change) in
 * addition to checking the shape of what they produce.
 */
class SchemaCapture
{
    /**
     * Capture the full schema a fresh install of the module produces
     * (InstallSchema followed by UpgradeSchema from version 0.0.0).
     *
     * @return RecordingSchemaTable[] keyed by logical table name
     * @throws \Zend_Db_Exception
     */
    public static function freshInstall(): array
    {
        $connection = new RecordingSchemaConnection();
        $setup      = new RecordingSchemaSetup($connection);

        (new InstallSchema())->install($setup, new RecordingModuleContext('0.0.0'));
        (new UpgradeSchema())->upgrade($setup, new RecordingModuleContext('0.0.0'));

        return $connection->created;
    }

    /**
     * Capture just what InstallSchema produces, letting the caller pre-declare
     * tables as already existing to exercise the "skip if present" guard.
     *
     * @param string[] $existingTables
     * @return RecordingSchemaConnection the connection, so callers can inspect
     *                                    both what was created and what was skipped
     * @throws \Zend_Db_Exception
     */
    public static function install(array $existingTables = []): RecordingSchemaConnection
    {
        $connection = new RecordingSchemaConnection();
        $connection->existingTables = $existingTables;
        $setup = new RecordingSchemaSetup($connection);

        (new InstallSchema())->install($setup, new RecordingModuleContext('0.0.0'));

        return $connection;
    }

    /**
     * Capture what UpgradeSchema produces for a given already-installed version.
     *
     * @param string $fromVersion the version the module is being upgraded from
     * @return RecordingSchemaConnection
     * @throws \Zend_Db_Exception
     */
    public static function upgradeFrom(string $fromVersion): RecordingSchemaConnection
    {
        $connection = new RecordingSchemaConnection();
        $setup = new RecordingSchemaSetup($connection);

        (new UpgradeSchema())->upgrade($setup, new RecordingModuleContext($fromVersion));

        return $connection;
    }
}
