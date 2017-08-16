<?php

namespace Pimgento\Family\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use \Magento\Framework\DB\Ddl\Table;

/**
 * @codeCoverageIgnore
 */
class UpgradeSchema implements UpgradeSchemaInterface
{
    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;

        $installer->startSetup();

        if (version_compare($context->getVersion(), '1.0.1', '<')) {
            /**
             * Create table 'pimgento_family_attribute_relations'
             */
            $table = $installer->getConnection()
                ->newTable($installer->getTable('pimgento_family_attribute_relations'))
                ->addColumn(
                    'id',
                    Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'nullable' => false, 'primary' => true],
                    'ID'
                )
                ->addColumn(
                    'family_entity_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['nullable' => false],
                    'Family Entity ID'
                )
                ->addColumn(
                    'attribute_code',
                    Table::TYPE_TEXT,
                    255,
                    ['nullable' => false],
                    'Attribute Code'
                )
                ->addColumn(
                    'created_at',
                    Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => Table::TIMESTAMP_INIT],
                    'Creation Time'
                )
                ->setComment('Pimgento Family Attribute Relations');

            $installer->getConnection()->createTable($table);
        }

        $installer->endSetup();
    }
}