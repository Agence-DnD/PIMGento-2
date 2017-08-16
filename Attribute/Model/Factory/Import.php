<?php

namespace Pimgento\Attribute\Model\Factory;

use \Pimgento\Import\Model\Factory;
use \Pimgento\Entities\Model\Entities;
use \Pimgento\Import\Helper\Config as helperConfig;
use \Magento\Framework\Event\ManagerInterface;
use \Magento\Framework\App\Cache\TypeListInterface;
use \Pimgento\Attribute\Helper\Type as helperType;
use \Pimgento\Attribute\Setup\AttributeSetup;
use \Magento\Framework\Module\Manager as moduleManager;
use \Magento\Framework\App\Config\ScopeConfigInterface as scopeConfig;
use \Zend_Db_Expr as Expr;
use \Exception;

class Import extends Factory
{
    /**
     * Product Entity Type Id
     * @var int
     */
    protected $_entityTypeId;

    /**
     * @var Entities
     */
    protected $_entities;

    /**
     * @var TypeListInterface
     */
    protected $_cacheTypeList;

    /**
     * @var helperType
     */
    protected $_helperType;

    /**
     * @var EavSetup
     */
    protected $_eavSetup;

    /**
     * @param \Pimgento\Entities\Model\Entities $entities
     * @param \Pimgento\Import\Helper\Config $helperConfig
     * @param \Magento\Framework\Module\Manager $moduleManager
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Pimgento\Attribute\Helper\Type $helperType
     * @param \Pimgento\Attribute\Setup\AttributeSetup $attributeSetup
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param array $data
     */
    public function __construct(
        Entities $entities,
        helperConfig $helperConfig,
        moduleManager $moduleManager,
        scopeConfig $scopeConfig,
        ManagerInterface $eventManager,
        helperType $helperType,
        AttributeSetup $attributeSetup,
        TypeListInterface $cacheTypeList,
        array $data = []
    )
    {
        parent::__construct($helperConfig, $eventManager, $moduleManager, $scopeConfig, $data);
        $this->_helperType = $helperType;
        $this->_eavSetup = $attributeSetup;
        $this->_entities = $entities;
        $this->_cacheTypeList = $cacheTypeList;
    }

    /**
     * Get the product entity type id
     *
     * @return int
     */
    protected function getEntityTypeId()
    {
        if (is_null($this->_entityTypeId)) {
            //@todo get it from the database
            $this->_entityTypeId = 4;
        }

        return $this->_entityTypeId;
    }

    /**
     * Create temporary table
     */
    public function createTable()
    {
        $file = $this->getFileFullPath();

        if (!is_file($file)) {
            $this->setContinue(false);
            $this->setStatus(false);
            $this->setMessage($this->getFileNotFoundErrorMessage());
        } else {
            $this->_entities->createTmpTableFromFile($file, $this->getCode(), array('type', 'code'));
        }
    }

    /**
     * Insert data into temporary table
     */
    public function insertData()
    {
        $file = $this->getFileFullPath();

        $count = $this->_entities->insertDataFromFile($file, $this->getCode());

        $this->setMessage(
            __('%1 line(s) found', $count)
        );
    }

    /**
     * Match code with entity
     */
    public function matchEntity()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();

        $select = $connection->select()
            ->from(
                'eav_attribute',
                array(
                    'import'     => new Expr('"attribute"'),
                    'code'       => 'attribute_code',
                    'entity_id'  => 'attribute_id',
                )
            )
            ->where('entity_type_id = ?', $this->getEntityTypeId());

        $connection->query(
            $connection->insertFromSelect(
                $select,  $resource->getTable('pimgento_entities'), array('import', 'code', 'entity_id'), 2
            )
        );

        $this->_entities->matchEntity($this->getCode(), 'code', 'eav_attribute', 'attribute_id');
    }

    /**
     * Match type with Magento logic
     */
    public function matchType()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $columns = $this->_helperType->getSpecificColumns();
        foreach ($columns as $name => $def) {
            $connection->addColumn($tmpTable, $name, $def['type']);
        }

        $select = $connection->select()
            ->from(
                $tmpTable,
                array_merge(
                    array('_entity_id', 'type'),
                    array_keys($columns)
                )
            );

        $data = $connection->fetchAssoc($select);

        foreach ($data as $id => $attribute) {
            $type = $this->_helperType->getType($attribute['type']);

            $connection->update($tmpTable, $type, array('_entity_id = ?' => $id));
        }
    }

    /**
     * Match family code with Magento group id
     */
    public function matchFamily()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());
        $familyAttributeRelationsTable = 'pimgento_family_attribute_relations';

        $connection->addColumn($tmpTable, '_attribute_set_id', 'VARCHAR(255) NULL');

        $importTmpTable = $connection->select()->from($tmpTable, array('code', '_entity_id'));
        $queryTmpTable = $connection->query($importTmpTable);

        while ($row = $queryTmpTable->fetch()) {
            $attributeCode = $row['code'];

            $importRelations = $connection
                ->select()
                ->from($familyAttributeRelationsTable, 'family_entity_id')
                ->where($connection
                    ->prepareSqlCondition('attribute_code', ['like' => $attributeCode])
                );
            $queryRelations = $connection->query($importRelations);

            $attributeIds = '';
            while ($innerRow = $queryRelations->fetch()) {
                $attributeIds .= $innerRow['family_entity_id'] . ',';
            }

            $connection->update($tmpTable, array('_attribute_set_id' => $attributeIds), '_entity_id=' . $row['_entity_id']);
        }
    }

    /**
     * Add attributes if not exists
     */
    public function addAttributes()
    {
        $columns = $this->_helperType->getSpecificColumns();
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $import = $connection->select()->from($tmpTable);
        $query  = $connection->query($import);

        while (($row = $query->fetch())) {

            /* Insert base data (ignore if already exists) */
            $values = array(
                'attribute_id'   => $row['_entity_id'],
                'entity_type_id' => $this->getEntityTypeId(),
                'attribute_code' => $row['code'],
            );
            $connection->insertOnDuplicate(
                $resource->getTable('eav_attribute'), $values, array_keys($values)
            );

            $values = array(
                'attribute_id' => $row['_entity_id'],
            );
            $connection->insertOnDuplicate(
                $resource->getTable('catalog_eav_attribute'), $values, array_keys($values)
            );

            /* Retrieve default admin label */
            $stores = $this->_helperConfig->getStores('store_id');

            $frontendLabel = __('Unknown');
            if (isset($stores[0])) {
                $admin = reset($stores[0]);
                if (isset($row['label-' . $admin['lang']])) {
                    $frontendLabel = $row['label-' . $admin['lang']];
                }
            }

            /* Retrieve attribute scope */
            $global = 1; // Global
            if ($row['scopable'] == 1) {
                $global = 2; // Website
            }
            if ($row['localizable'] == 1) {
                $global = 0; // Store View
            }

            $data = [
                'entity_type_id' => $this->getEntityTypeId(),
                'attribute_code' => $row['code'],
                'frontend_label' => $frontendLabel,
                'is_global'      => $global,
            ];
            foreach ($columns as $column => $def) {
                if (!$def['only_init']) {
                    $data[$column] = $row[$column];
                }
            }


            $defaultValues = [];
            if ($row['_is_new'] == 1) {
                $defaultValues = [
                    'backend_table'                 => null,
                    'frontend_class'                => null,
                    'is_required'                   => 0,
                    'is_user_defined'               => 1,
                    'default_value'                 => null,
                    'is_unique'                     => $row['unique'],
                    'note'                          => null,
                    'is_visible'                    => 1,
                    'is_system'                     => 1,
                    'input_filter'                  => null,
                    'multiline_count'               => 0,
                    'validate_rules'                => null,
                    'data_model'                    => null,
                    'sort_order'                    => 0,
                    'is_used_in_grid'               => 0,
                    'is_visible_in_grid'            => 0,
                    'is_filterable_in_grid'         => 0,
                    'is_searchable_in_grid'         => 0,
                    'frontend_input_renderer'       => null,
                    'is_searchable'                 => 0,
                    'is_filterable'                 => 0,
                    'is_comparable'                 => 0,
                    'is_visible_on_front'           => 0,
                    'is_wysiwyg_enabled'            => 0,
                    'is_html_allowed_on_front'      => 0,
                    'is_visible_in_advanced_search' => 0,
                    'is_filterable_in_search'       => 0,
                    'used_in_product_listing'       => 0,
                    'used_for_sort_by'              => 0,
                    'apply_to'                      => null,
                    'position'                      => 0,
                    'is_used_for_promo_rules'       => 0,
                ];

                foreach (array_keys($columns) as $column) {
                    $data[$column] = $row[$column];
                }
            }

            $data = array_merge($defaultValues, $data);

            $this->_eavSetup->updateAttribute(
                $this->getEntityTypeId(),
                $row['_entity_id'],
                $data,
                null,
                0
            );

            /* Add Attribute to group and family */
            if ($row['_attribute_set_id'] && $row['group']) {
                $attributeSetIds = explode(',', $row['_attribute_set_id']);

                foreach ($attributeSetIds as $attributeSetId) {
                    if (is_numeric($attributeSetId)) {
                        $this->_eavSetup->addAttributeGroup(
                            $this->getEntityTypeId(),
                            $attributeSetId,
                            ucfirst($row['group'])
                        );
                        $this->_eavSetup->addAttributeToSet(
                            $this->getEntityTypeId(),
                            $attributeSetId,
                            ucfirst($row['group']),
                            $row['_entity_id']
                        );
                    }
                }
            }

            /* Add store labels */
            $stores = $this->_helperConfig->getStores('lang');

            foreach ($stores as $lang => $data) {
                if (isset($row['label-' . $lang])) {
                    foreach ($data as $store) {

                        $exists = $connection->fetchOne(
                            $connection->select()
                                ->from($resource->getTable('eav_attribute_label'))
                                ->where('attribute_id = ?', $row['_entity_id'])
                                ->where('store_id = ?', $store['store_id'])
                        );

                        if ($exists) {
                            $values = array(
                                'value' => $row['label-' . $lang]
                            );
                            $where = array(
                                'attribute_id = ?' => $row['_entity_id'],
                                'store_id = ?' => $store['store_id']
                            );
                            $connection->update($resource->getTable('eav_attribute_label'), $values, $where);
                        } else {
                            $values = array(
                                'attribute_id' => $row['_entity_id'],
                                'store_id' => $store['store_id'],
                                'value' => $row['label-' . $lang]
                            );
                            $connection->insert($resource->getTable('eav_attribute_label'), $values);
                        }
                    }
                }
            }

        }

    }

    /**
     * Drop temporary table
     */
    public function dropTable()
    {
        $this->_entities->dropTable($this->getCode());
    }

    /**
     * Clean cache
     */
    public function cleanCache()
    {
        $types = array(
            \Magento\Framework\App\Cache\Type\Block::TYPE_IDENTIFIER,
            \Magento\PageCache\Model\Cache\Type::TYPE_IDENTIFIER,
            \Magento\Eav\Model\Cache\Type::TYPE_IDENTIFIER,
        );

        foreach ($types as $type) {
            $this->_cacheTypeList->cleanType($type);
        }

        $this->setMessage(
            __('Cache cleaned for: %1', join(', ', $types))
        );
    }

}