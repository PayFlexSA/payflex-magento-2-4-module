<?php
namespace Payflex\Gateway\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;

class UpgradeSchema implements  UpgradeSchemaInterface
{
    public function upgrade(SchemaSetupInterface $setup,
                            ModuleContextInterface $context){
        if (version_compare($context->getVersion(), '1.0.1') < 0) {
            $installer = $setup;

            $installer->startSetup();

            $accessTokenTableName = $installer->getTable('payflex_gateway_accessToken');
            // if exists, then this module should be installed before, just skip it. Use upgrade command to updata the table.
            if ($installer->getConnection()->isTableExists($accessTokenTableName) != true) {
                $accessTokenTable = $installer->getConnection()
                    ->newTable($accessTokenTableName)
                    ->addColumn(
                        'id',
                        Table::TYPE_INTEGER,
                        11,
                        [
                            'identity' => true,
                            'unsigned' => true,
                            'nullable' => false,
                            'primary' => true
                        ],
                        'ID'
                    )
                    ->addColumn(
                        'store_id',
                        Table::TYPE_INTEGER,
                        11,
                        [],
                        'Store ID'
                    )
                    ->addColumn(
                        'token',
                        Table::TYPE_TEXT,
                        '2M',
                        [],
                        'Token'
                    )
                    ->addColumn(
                        'expire',
                        Table::TYPE_TEXT,
                        255,
                        [],
                        'Expire'
                    )
                    ->setComment('Payflex access token Table');
                $installer->getConnection()->createTable($accessTokenTable);
            }
            $installer->endSetup();
        }
    }
}
