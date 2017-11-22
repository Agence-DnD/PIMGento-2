<?php

namespace Pimgento\Product\Helper;

use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;
use Pimgento\Entities\Model\Entities;
use \Pimgento\Staging\Helper\Config as StagingConfigHelper;
use \Pimgento\Staging\Helper\Import as StagingHelper;

/**
 * Helper class to handle product related staging support.
 *
 * @package Pimgento\Product\Helper
 */
class Staging extends AbstractHelper
{
    /**
     * Constants to configuration profile.
     */
    const CONFIG_PROFILE = 'product';


    /**
     * @var \Pimgento\Import\Helper\Config
     */
    protected $stagingConfigHelper;

    /**
     * @var StagingHelper
     */
    protected $stagingHelper;

    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param StagingConfigHelper $statingConfigHelper
     * @param StagingHelper $stagingHelper
     */
    public function __construct(
        Context $context,
        StagingConfigHelper $statingConfigHelper,
        StagingHelper $stagingHelper
    ) {
        $this->stagingConfigHelper = $statingConfigHelper;
        $this->stagingHelper = $stagingHelper;

        parent::__construct($context);
    }

    /**
     * Updates the created & updated in dates of configurable products with the ones of the simple products.
     *
     * @param Entities $entities
     * @param $tmpTable
     * @param $entityTableCode
     * @param $code
     * @param $stagingMode
     */
    public function updateConfigurableStages(Entities $entities, $tmpTable, $entityTableCode, $code, $stagingMode)
    {
        $connection = $entities->getResource()->getConnection();

        $query = "
                UPDATE $tmpTable tc, $tmpTable ts
                SET tc.created_in = ts.created_in, tc.updated_in = ts.updated_in
                WHERE tc._first_children = ts.sku
            ";
        $connection->query($query);
    }

    /**
     * Duplicate values into multiple stages. This is necessary for the all mode when all stages are updated with
     * the same values & also to duplicate the values properly for the initial stage that might be in multiple pieces.
     *
     * @param Entities $entities
     * @param string $tmpTable
     * @param string $condition
     * @param string $dataTable
     */
    public function updateAllStageValues(
        Entities $entities,
        $tmpTable,
        $condition = 't._row_id != e.row_id',
        $dataTable = null
    ) {
        if (is_null($dataTable)) {
            $dataTable = $tmpTable;
        }

        $connection = $entities->getResource()->getConnection();

        $columns = array_keys($connection->describeTable($dataTable));
        $column[] = 'options_container';
        $column[] = 'tax_class_id';
        $column[] = 'visibility';

        $except = array(
            '_entity_id',
            '_is_new',
            '_status',
            '_type_id',
            '_options_container',
            '_tax_class_id',
            '_attribute_set_id',
            '_visibility',
            '_children',
            '_first_children',
            '_axis',
            'sku',
            'categories',
            'family',
            'groups',
            'enabled',
            'created_in',
            'updated_in',
        );

        if ($connection->tableColumnExists($tmpTable, 'enabled')) {
            $column[] = 'status';
        }

        foreach ($columns as $column) {
            if (in_array($column, $except)) {
                continue;
            }

            if (preg_match('/-unit/', $column)) {
                continue;
            }

            $columnPrefix = explode('-', $column);
            $columnPrefix = reset($columnPrefix);

            $entities
                ->getResource()
                ->updateAllStageValues (
                    $tmpTable,
                    $connection->getTableName('catalog_product_entity'),
                    4,
                    $columnPrefix,
                    $condition
                );
        }
    }

    /**
     * Duplicate relations between products for all stages.
     *
     * @see updateAllStageValues for more information on why it's used.
     *
     * @param Entities $entities
     * @param string $tmpTable
     * @param string $joinCondition
     */
    public function updateAllStageRelations(Entities $entities, $tmpTable, $joinCondition = 't._row_id != e.row_id')
    {
        $connection = $entities->getResource()->getConnection();

        $entityTable = $connection->getTableName('catalog_product_entity');
        $linkTable = $connection->getTableName('catalog_product_link');

        $select = $this->stagingHelper->getBaseStageDuplicationSelect($connection, $entityTable, $tmpTable, $joinCondition);
        $select->joinInner(
            ['u' => $linkTable],
            'u.product_id = t._row_id',
            []
        );

        $select->columns(['e.row_id', 'u.linked_product_id', 'u.link_type_id']);

        $select->setPart('disable_staging_preview', true);

        $insert = $connection->insertFromSelect(
            $select,
            $linkTable,
            array('product_id', 'linked_product_id', 'link_type_id'),
            1
        );
        $connection->query($insert);
    }

    /**
     * Duplicate relations for configurable products for all stages.
     *
     * @see updateAllStageValues for more information on why it's used.
     *
     * @param Entities $entities
     * @param string $tmpTable
     * @param string $joinCondition
     */
    public function updateAllStageConfigurables(Entities $entities, $tmpTable, $joinCondition = 't._row_id != e.row_id')
    {
        $connection = $entities->getResource()->getConnection();

        $entityTable = $connection->getTableName('catalog_product_entity');

        $attributeTable = $connection->getTableName('catalog_product_super_attribute');
        $labelTable = $connection->getTableName('catalog_product_super_attribute_label');
        $relationTable = $connection->getTableName('catalog_product_relation');
        $linkTable = $connection->getTableName('catalog_product_super_link');

        $baseSelect = $this->stagingHelper
            ->getBaseStageDuplicationSelect($connection, $entityTable, $tmpTable, $joinCondition);

        /**
         * Duplicating Data in catalog_product_super_attribute
         */
        $select = clone $baseSelect;
        $select->joinInner(
            ['u' => $attributeTable],
            'u.product_id = t._row_id',
            []
        )->columns(['e.row_id', 'u.attribute_id', 'u.position']);

        $insert = $connection->insertFromSelect(
            $select,
            $attributeTable,
            array('product_id', 'attribute_id', 'position'),
            1
        );
        $connection->query($insert);

        /**
         * Duplicating Data in catalog_product_super_attribute_label
         */
        $select = clone $baseSelect;
        $select->joinInner(
            ['u_new' => $attributeTable],
            'u_new.product_id = e.row_id',
            []
        )->joinInner(
            ['u_source' => $attributeTable],
            'u_source.product_id = t._row_id',
            []
        )->joinInner(
            ['l_source' => $labelTable],
            'l_source.product_super_attribute_id = u_source.product_super_attribute_id',
            []
        )->columns(['u_new.product_super_attribute_id', 'l_source.store_id', 'l_source.use_default', 'l_source.value']);

        $insert = $connection->insertFromSelect(
            $select,
            $labelTable,
            array('product_super_attribute_id', 'store_id', 'use_default', 'value'),
            1
        );
        $connection->query($insert);

        /**
         * Duplicating Data in catalog_product_relation
         */
        $select = clone $baseSelect;
        $select->joinInner(
            ['u' => $relationTable],
            'u.parent_id = t._row_id',
            []
        )->columns(['e.row_id', 'u.child_id']);

        $insert = $connection->insertFromSelect(
            $select,
            $relationTable,
            array('parent_id', 'child_id'),
            1
        );
        $connection->query($insert);
        /**
         * Duplicating Data in catalog_product_super_link
         */
        $select = clone $baseSelect;
        $select->joinInner(
            ['u' => $linkTable],
            'u.parent_id = t._row_id',
            []
        )->columns(['e.row_id', 'u.product_id']);

        $insert = $connection->insertFromSelect(
            $select,
            $linkTable,
            array('parent_id', 'product_id'),
            1
        );
        $connection->query($insert);
    }

    /**
     * Duplicate medias between products for all stages.
     *
     * @see updateAllStageValues for more information on why it's used.
     *
     * @param Entities $entities
     * @param string $tmpTable
     * @param string $joinCondition
     */
    public function updateAllStageMedias(Entities $entities, $tmpTable, $joinCondition = 't._row_id != e.row_id')
    {
        $connection = $entities->getResource()->getConnection();

        $entityTable = $connection->getTableName('catalog_product_entity');
        $mediaTable = $connection->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        $select = $this->stagingHelper
            ->getBaseStageDuplicationSelect($connection, $entityTable, $tmpTable, $joinCondition);
        $select->joinInner(
            ['u' => $mediaTable],
            'u.row_id = t._row_id',
            []
        );

        $select->columns(['u.value_id', 'e.row_id']);

        $select->setPart('disable_staging_preview', true);

        $insert = $connection->insertFromSelect(
            $select,
            $mediaTable,
            array('value_id', 'row_id'),
            1
        );
        $connection->query($insert);
    }
}