<?php

namespace Pimgento\Product\Model\Factory;

use \Pimgento\Import\Model\Factory;
use \Pimgento\Entities\Model\Entities;
use \Pimgento\Import\Helper\Config as helperConfig;
use \Pimgento\Import\Helper\UrlRewrite as urlRewriteHelper;
use \Pimgento\Product\Helper\Config as productHelper;
use \Pimgento\Product\Helper\Media as mediaHelper;
use \Pimgento\Product\Model\Factory\Import\Related;
use \Pimgento\Product\Model\Factory\Import\Media;
use \Magento\Catalog\Model\Product;
use \Magento\Catalog\Model\Product\Link as Link;
use \Magento\Framework\Event\ManagerInterface;
use \Magento\Framework\App\Cache\TypeListInterface;
use \Magento\Eav\Model\Entity\Attribute\SetFactory;
use \Magento\Framework\Module\Manager as moduleManager;
use \Magento\Framework\App\Config\ScopeConfigInterface as scopeConfig;
use \Magento\Framework\DB\Adapter\AdapterInterface;
use \Magento\Staging\Model\VersionManager;
use \Zend_Db_Expr as Expr;

class Import extends Factory
{
    /**
     * @var Entities
     */
    protected $_entities;

    /**
     * @var \Magento\Eav\Model\Entity\Attribute\SetFactory
     */
    protected $_attributeSetFactory;

    /**
     * @var TypeListInterface
     */
    protected $_cacheTypeList;

    /**
     * @var \Pimgento\Product\Helper\Config
     */
    protected $_productHelper;

    /**
     * @var \Pimgento\Product\Helper\Media
     */
    protected $_mediaHelper;

    /**
     * list of allowed type_id that can be imported
     * @var string[]
     */
    protected $_allowedTypeId = ['simple', 'virtual'];

    /**
     * @var urlRewriteHelper
     */
    protected $_urlRewriteHelper;

    /**
     * @var Media $_media
     */
    protected $_media;

    /**
     * @var Related $_related
     */
    protected $_related;

    /**
     * @var Product $_product
     */
    protected $_product;

    /**
     * PHP Constructor
     *
     * @param \Pimgento\Import\Helper\Config                     $helperConfig
     * @param \Magento\Framework\Event\ManagerInterface          $eventManager
     * @param \Magento\Framework\Module\Manager                  $moduleManager
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Pimgento\Entities\Model\Entities                  $entities
     * @param \Magento\Framework\App\Cache\TypeListInterface     $cacheTypeList
     * @param \Magento\Eav\Model\Entity\Attribute\SetFactory     $attributeSetFactory
     * @param \Pimgento\Product\Helper\Config                    $productHelper
     * @param \Pimgento\Product\Helper\Media                     $mediaHelper
     * @param urlRewriteHelper                                   $urlRewriteHelper
     * @param Related                                            $related
     * @param Media                                              $media
     * @param Product                                            $product
     * @param array                                              $data
     */
    public function __construct(
        helperConfig $helperConfig,
        ManagerInterface $eventManager,
        moduleManager $moduleManager,
        scopeConfig $scopeConfig,
        Entities $entities,
        TypeListInterface $cacheTypeList,
        SetFactory $attributeSetFactory,
        productHelper $productHelper,
        mediaHelper $mediaHelper,
        urlRewriteHelper $urlRewriteHelper,
        Related $related,
        Media $media,
        Product $product,
        array $data = []
    ) {
        parent::__construct($helperConfig, $eventManager, $moduleManager, $scopeConfig, $data);

        $this->_entities = $entities;
        $this->_cacheTypeList = $cacheTypeList;
        $this->_attributeSetFactory = $attributeSetFactory;
        $this->_productHelper = $productHelper;
        $this->_mediaHelper = $mediaHelper;
        $this->_urlRewriteHelper = $urlRewriteHelper;
        $this->_related = $related;
        $this->_media = $media;
        $this->_product = $product;
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
            $this->_entities->createTmpTableFromFile($file, $this->getCode(), array('sku'));
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
     * Add required data
     */
    public function addRequiredData()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $connection->addColumn($tmpTable, '_type_id', 'VARCHAR(255) NOT NULL DEFAULT "simple"');
        $connection->addColumn($tmpTable, '_options_container', 'VARCHAR(255) NOT NULL DEFAULT "container2"');
        $connection->addColumn($tmpTable, '_tax_class_id', 'INT(11) NOT NULL DEFAULT 0'); // None
        $connection->addColumn($tmpTable, '_attribute_set_id', 'INT(11) NOT NULL DEFAULT "4"'); // Default
        $connection->addColumn($tmpTable, '_visibility', 'INT(11) NOT NULL DEFAULT "4"'); // catalog, search
        $connection->addColumn($tmpTable, '_status', 'INT(11) NOT NULL DEFAULT "2"'); // Disabled

        if (!$connection->tableColumnExists($tmpTable, 'url_key')) {
            $connection->addColumn($tmpTable, 'url_key', 'varchar(255) NOT NULL DEFAULT ""');
            $connection->update($tmpTable, array('url_key' => new Expr('LOWER(`sku`)')));
        }

        if ($connection->tableColumnExists($tmpTable, 'enabled')) {
            $connection->update($tmpTable, array('_status' => new Expr('IF(`enabled` <> 1, 2, 1)')));
        }

        if ($connection->tableColumnExists($tmpTable, 'groups')) {
            $connection->update($tmpTable, array('_visibility' => new Expr('IF(`groups` <> "", 1, 4)')));
        }

        if ($connection->tableColumnExists($tmpTable, 'type_id')) {
            $types = $connection->quote($this->_allowedTypeId);
            $connection->update(
                $tmpTable,
                array(
                    '_type_id' => new Expr("IF(`type_id` IN ($types), `type_id`, 'simple')")
                )
            );
        }

        $matches = $this->_scopeConfig->getValue('pimgento/product/attribute_mapping');

        if ($matches) {
            $matches = unserialize($matches);
            if (is_array($matches)) {
                $stores = array_merge(
                    $this->_helperConfig->getStores(array('lang')), // en_US
                    $this->_helperConfig->getStores(array('lang', 'channel_code')), // en_US-channel
                    $this->_helperConfig->getStores(array('channel_code')), // channel
                    $this->_helperConfig->getStores(array('currency')), // USD
                    $this->_helperConfig->getStores(array('channel_code', 'currency')), // channel-USD
                    $this->_helperConfig->getStores(array('lang', 'channel_code', 'currency')) // en_US-channel-USD
                );
                foreach ($matches as $match) {
                    $pimAttr = $match['pim_attribute'];
                    $magentoAttr = $match['magento_attribute'];
                    $this->_entities->copyColumn($tmpTable, $pimAttr, $magentoAttr);

                    foreach ($stores as $local => $affected) {
                        $this->_entities->copyColumn($tmpTable, $pimAttr . '-' . $local, $magentoAttr . '-' . $local);
                    }
                }

            }
        }
    }

    /**
     * Create Configurable products
     */
    public function createConfigurable()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        if (!$this->moduleIsEnabled('Pimgento_Variant')) {
            $this->setStatus(false);
            $this->setMessage(
                __('Module Pimgento_Variant is not enabled')
            );
        } else if (!$connection->tableColumnExists($tmpTable, 'groups')) {
            $this->setStatus(false);
            $this->setMessage(
                __('Column groups not found')
            );
        } else {
            $connection->addColumn($tmpTable, '_children', 'TEXT NULL');
            $connection->addColumn($tmpTable, '_axis', 'VARCHAR(255) NULL');

            $data = array(
                'sku' => 'e.groups',
                'url_key' => 'e.groups',
                '_children' => new Expr('GROUP_CONCAT(e.sku SEPARATOR ",")'),
                '_type_id' => new Expr('"configurable"'),
                '_options_container' => new Expr('"container1"'),
                '_status' => 'e._status',
                '_axis' => 'v.axis'
            );

            if ($connection->tableColumnExists($tmpTable, 'family')) {
                $data['family'] = 'e.family';
            }

            if ($connection->tableColumnExists($tmpTable, 'categories')) {
                $data['categories'] = 'e.categories';
            }

            $additional = $this->_scopeConfig->getValue('pimgento/product/configurable_attributes');

            if ($additional) {
                $additional = unserialize($additional);
                if (is_array($additional)) {

                    $stores = array_merge(
                        $this->_helperConfig->getStores(array('lang')), // en_US
                        $this->_helperConfig->getStores(array('lang', 'channel_code')), // en_US-channel
                        $this->_helperConfig->getStores(array('channel_code')), // channel
                        $this->_helperConfig->getStores(array('currency')), // USD
                        $this->_helperConfig->getStores(array('channel_code', 'currency')), // channel-USD
                        $this->_helperConfig->getStores(array('lang', 'channel_code', 'currency')) // en_US-channel-USD
                    );

                    foreach ($additional as $attribute) {
                        $attr  = $attribute['attribute'];
                        $value = $attribute['value'];

                        $columns = array(trim($attr));
                        foreach ($stores as $local => $affected) {
                            $columns[] = trim($attr) . '-' . $local;
                        }

                        foreach ($columns as $column) {

                            if ($column == 'enabled') {
                                if ($connection->tableColumnExists($tmpTable, 'enabled')) {
                                    $column = '_status';
                                    if ($value == "0") {
                                        $value = "2";
                                    }
                                }
                            }

                            if ($connection->tableColumnExists($tmpTable, $column)) {
                                if (!strlen($value)) {
                                    if ($connection->tableColumnExists($resource->getTable('pimgento_variant'), $column)) {
                                        $data[$column] = 'v.' . $column;
                                    } else {
                                        $data[$column] = 'e.' . $column;
                                    }
                                } else {
                                    $data[$column] = new Expr('"' . $value . '"');
                                }
                            }
                        }
                    }
                }

            }

            $configurable = $connection->select()
                ->from(array('e' => $tmpTable), $data)
                ->joinInner(
                    array('v' => $resource->getTable('pimgento_variant')),
                    'e.groups = v.code',
                    array()
                )
                ->where('groups <> ""')
                ->group('e.groups');

            $connection->query(
                $connection->insertFromSelect($configurable, $tmpTable, array_keys($data))
            );
        }
    }

    /**
     * Match code with entity
     */
    public function matchEntity()
    {
        $this->_entities->matchEntity($this->getCode(), 'sku', 'catalog_product_entity', 'entity_id');
    }

    /**
     * Update product attribute set id
     */
    public function updateAttributeSetId()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        if (!$connection->tableColumnExists($tmpTable, 'family')) {
            $this->setStatus(false);
            $this->setMessage(
                __('Column family is missing')
            );
        } else {
            $families = $connection->select()
                ->from(false, array('_attribute_set_id' => 'c.entity_id'))
                ->joinLeft(
                    array('c' => $resource->getTable('pimgento_entities')),
                    'p.family = c.code AND c.import = "family"',
                    array()
                );

            $connection->query(
                $connection->updateFromSelect($families, array('p' => $tmpTable))
            );

            $noFamily = $connection->fetchOne(
                $connection->select()
                    ->from($tmpTable, array('COUNT(*)'))
                    ->where('_attribute_set_id = ?', 0)
            );

            if ($noFamily) {
                $this->setStatus(false);
                $this->setMessage(
                    __('Warning: %1 product(s) without family. Please try to import families.', $noFamily)
                );
            }

            $connection->update(
                $tmpTable,
                array('_attribute_set_id' => $this->_product->getDefaultAttributeSetId()),
                array('_attribute_set_id = ?' => 0)
            );
        }
    }

    /**
     * Replace option code by id
     */
    public function updateOption()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $columns = array_keys($connection->describeTable($tmpTable));

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
            '_axis',
            'sku',
            'categories',
            'family',
            'groups',
            'url_key',
            'enabled',
        );

        // Append the list of other attributes to be excluded (attributes that are not using options)
        $productEntityTypeId = $connection->query(
            $connection->select()
                       ->from ($connection->getTableName('eav_entity_type'),'entity_type_id')
                       ->where('entity_type_code = ?','catalog_product')
        )->fetchColumn(0);

        $codesToExclude = $connection->query($connection->select()
                                                        ->from($connection->getTableName('eav_attribute'),'attribute_code')
                                                        ->where('frontend_input NOT IN (?)',array('select','multiselect'))
                                                        ->where('entity_type_id = ?', $productEntityTypeId)
        )->fetchAll(null,0);


        foreach($codesToExclude as $line) {
            $except[] = $line['attribute_code'];
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

            // Checking if the new generated columnPrefix is also part of the $except array
            if (in_array($columnPrefix, $except)) {
                continue;
            }

            if ($connection->tableColumnExists($tmpTable, $column)) {
                //get number of chars to remove from code in order to use the substring.
                $prefixL = strlen($columnPrefix . '_') + 1;

                // Sub select to increase performance versus FIND_IN_SET
                $subSelect = $connection->select()
                    ->from(
                        array('c' => $resource->getTable('pimgento_entities')),
                        array('code' => 'SUBSTRING(`c`.`code`,' . $prefixL . ')', 'entity_id' => 'c.entity_id')
                    )
                    ->where("c.code like '".$columnPrefix."_%' ")
                    ->where("c.import = ?", 'option');

                // if no option no need to continue process
                if (!$connection->query($subSelect)->rowCount()) {
                    continue;
                }
                //in case of multiselect
                $conditionJoin = "IF ( locate(',', `".$column."`) > 0 , ". "`p`.`".$column."` like ".
                                 new Expr("CONCAT('%', `c1`.`code`, '%')") .", `p`.`".$column."` = `c1`.`code` )";

                $select = $connection->select()
                    ->from(
                        array('p' => $tmpTable),
                        array(
                            'sku'       => 'p.sku',
                            'entity_id' => 'p._entity_id'
                        )
                    )
                    ->joinInner(
                        array('c1' => new Expr('('.(string) $subSelect.')')),
                        new Expr($conditionJoin),
                        array(
                            $column => new Expr('GROUP_CONCAT(`c1`.`entity_id` SEPARATOR ",")')
                        )
                    )
                    ->group('p.sku');

                $connection->query(
                    $connection->insertFromSelect($select, $tmpTable, array('sku', '_entity_id', $column), 1)
                );
            }
        }
    }

    /**
     * Create product entities
     */
    public function createEntities()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        if ($connection->isTableExists($resource->getTable('sequence_product'))) {
            $values = array(
                'sequence_value' => '_entity_id',
            );
            $parents = $connection->select()->from($tmpTable, $values);
            $connection->query(
                $connection->insertFromSelect(
                    $parents, $resource->getTable('sequence_product'), array_keys($values), 1
                )
            );
        }

        $values = array(
            'entity_id'        => '_entity_id',
            'attribute_set_id' => '_attribute_set_id',
            'type_id'          => '_type_id',
            'sku'              => 'sku',
            'has_options'      => new Expr(0),
            'required_options' => new Expr(0),
            'updated_at'       => new Expr('now()'),
        );

        $table = $resource->getTable('catalog_product_entity');

        $columnIdentifier = $this->_entities->getColumnIdentifier($table);

        if ($columnIdentifier == 'row_id') {
            $values['row_id'] = '_entity_id';
        }

        $parents = $connection->select()->from($tmpTable, $values);
        $connection->query(
            $connection->insertFromSelect(
                $parents, $table, array_keys($values), 1
            )
        );

        $values = array(
            'created_at' => new Expr('now()')
        );
        $connection->update($table, $values, 'created_at IS NULL');

        if ($columnIdentifier == 'row_id') {
            $values = [
                'created_in' => new Expr(1),
                'updated_in' => new Expr(VersionManager::MAX_VERSION),
            ];
            $connection->update($table, $values, 'created_in = 0 AND updated_in = 0');
        }
    }

    /**
     * Set values to attributes
     */
    public function setValues()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $stores = array_merge(
            $this->_helperConfig->getStores(array('lang')), // en_US
            $this->_helperConfig->getStores(array('lang', 'channel_code')), // en_US-channel
            $this->_helperConfig->getStores(array('channel_code')), // channel
            $this->_helperConfig->getStores(array('currency')), // USD
            $this->_helperConfig->getStores(array('channel_code', 'currency')), // channel-USD
            $this->_helperConfig->getStores(array('lang', 'channel_code', 'currency')) // en_US-channel-USD
        );

        $columns = array_keys($connection->describeTable($tmpTable));

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
            '_axis',
            'sku',
            'categories',
            'family',
            'groups',
            'enabled',
        );

        $values = array(
            0 => array(
                'options_container' => '_options_container',
                'tax_class_id'      => '_tax_class_id',
                'visibility'        => '_visibility',
            )
        );

        if ($connection->tableColumnExists($tmpTable, 'enabled')) {
            $values[0]['status'] = '_status';
        }

        $taxClasses = $this->_productHelper->getProductTaxClasses();
        if (count($taxClasses)) {
            foreach ($taxClasses as $storeId => $taxClassId) {
                $values[$storeId]['tax_class_id'] = new Expr($taxClassId);
            }
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

            foreach ($stores as $suffix => $affected) {
                if (preg_match('/^' . $columnPrefix . '-' . $suffix . '$/', $column)) {
                    foreach ($affected as $store) {
                        if (!isset($values[$store['store_id']])) {
                            $values[$store['store_id']] = array();
                        }
                        $values[$store['store_id']][$columnPrefix] = $column;
                    }
                }
            }

            if (!isset($values[0][$columnPrefix])) {
                $values[0][$columnPrefix] = $column;
            }
        }

        foreach($values as $storeId => $data) {
            $this->_entities->setValues(
                $this->getCode(), $resource->getTable('catalog_product_entity'), $data, 4, $storeId, 1
            );
        }
    }

    /**
     * Link configurable with children
     */
    public function linkConfigurable()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        if (!$this->moduleIsEnabled('Pimgento_Variant')) {
            $this->setStatus(false);
            $this->setMessage(
                __('Module Pimgento_Variant is not enabled')
            );
        } else if (!$connection->tableColumnExists($tmpTable, 'groups')) {
            $this->setStatus(false);
            $this->setMessage(
                __('Column groups not found')
            );
        } else {
            $stores = $this->_helperConfig->getStores('store_id');

            $query = $connection->query(
                $connection->select()
                    ->from(
                        $tmpTable,
                        array(
                            '_entity_id',
                            '_axis',
                            '_children'
                        )
                    )
                    ->where('_type_id = ?', 'configurable')
                    ->where('_axis IS NOT NULL')
                    ->where('_children IS NOT NULL')
            );

            $stepSize = 500;
            $valuesLabels = [];
            $valuesRelations = [];
            $valuesSuperLink = [];
            while (($row = $query->fetch())) {
                $attributes = explode(',', $row['_axis']);

                $position = 0;

                foreach ($attributes as $id) {
                    if (!is_numeric($id)) {
                        continue;
                    }

                    $hasOptions = $connection->fetchOne(
                        $connection->select()
                            ->from($resource->getTable('eav_attribute_option'), array(new Expr(1)))
                            ->where('attribute_id = ?', $id)
                            ->limit(1)
                    );

                    if (!$hasOptions) {
                        continue;
                    }

                    /* catalog_product_super_attribute */
                    $values = array(
                        'product_id' => $row['_entity_id'],
                        'attribute_id' => $id,
                        'position' => $position++,
                    );
                    $connection->insertOnDuplicate(
                        $resource->getTable('catalog_product_super_attribute'), $values, array()
                    );

                    /* catalog_product_super_attribute_label */
                    $superAttributeId = $connection->fetchOne(
                        $connection->select()
                            ->from($resource->getTable('catalog_product_super_attribute'))
                            ->where('attribute_id = ?', $id)
                            ->where('product_id = ?', $row['_entity_id'])
                            ->limit(1)
                    );

                    foreach ($stores as $storeId => $affected) {
                        $valuesLabels[] = array(
                            'product_super_attribute_id' => $superAttributeId,
                            'store_id' => $storeId,
                            'use_default' => 0,
                            'value' => ''
                        );
                    }

                    $children = explode(',', $row['_children']);

                    /* catalog_product_relation & catalog_product_super_link */
                    foreach ($children as $child) {
                        $childId = $connection->fetchOne(
                            $connection->select()
                                ->from(
                                    $resource->getTable('catalog_product_entity'),
                                    array(
                                        'entity_id'
                                    )
                                )
                                ->where('sku = ?', $child)
                                ->limit(1)
                        );

                        if ($childId) {
                            /* catalog_product_relation */
                            $valuesRelations[] = array(
                                'parent_id' => $row['_entity_id'],
                                'child_id' => $childId,
                            );

                            /* catalog_product_super_link */
                            $valuesSuperLink[] = array(
                                'product_id' => $childId,
                                'parent_id' => $row['_entity_id'],
                            );
                        }
                    }


                    if (count($valuesSuperLink)  > $stepSize) {
                        $connection->insertOnDuplicate(
                            $resource->getTable('catalog_product_super_attribute_label'),
                            $valuesLabels,
                            array()
                        );

                        $connection->insertOnDuplicate(
                            $resource->getTable('catalog_product_relation'),
                            $valuesRelations,
                            array()
                        );
                        $connection->insertOnDuplicate(
                            $resource->getTable('catalog_product_super_link'),
                            $valuesSuperLink,
                            array()
                        );


                        $valuesLabels = [];
                        $valuesRelations = [];
                        $valuesSuperLink = [];
                    }
                }
            }

            if (count($valuesSuperLink)  > 0) {
                $connection->insertOnDuplicate(
                    $resource->getTable('catalog_product_super_attribute_label'),
                    $valuesLabels,
                    array()
                );

                $connection->insertOnDuplicate(
                    $resource->getTable('catalog_product_relation'),
                    $valuesRelations,
                    array()
                );
                $connection->insertOnDuplicate(
                    $resource->getTable('catalog_product_super_link'),
                    $valuesSuperLink,
                    array()
                );
            }
        }
    }

    /**
     * Set website
     */
    public function setWebsites()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $websites = $this->_helperConfig->getStores('website_id');

        foreach ($websites as $websiteId => $affected) {
            if ($websiteId == 0) {
                continue;
            }

            $select = $connection->select()
                ->from(
                    $tmpTable,
                    array(
                        'product_id' => '_entity_id',
                        'website_id' => new Expr($websiteId)
                    )
                );
            $connection->query(
                $connection->insertFromSelect(
                    $select, $resource->getTable('catalog_product_website'), array('product_id', 'website_id'), 1
                )
            );
        }
    }

    /**
     * Set categories
     */
    public function setCategories()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        if (!$connection->tableColumnExists($tmpTable, 'categories')) {
            $this->setStatus(false);
            $this->setMessage(
                __('Column categories not found')
            );
        } else {

            $select = $connection->select()
                ->from(
                    array(
                        'c' => $resource->getTable('pimgento_entities')
                    ),
                    array()
                )
                ->joinInner(
                    array('p' => $tmpTable),
                    'FIND_IN_SET(`c`.`code`, `p`.`categories`) AND `c`.`import` = "category"',
                    array(
                        'category_id' => 'c.entity_id',
                        'product_id'  => 'p._entity_id'
                    )
                )
                ->joinInner(
                    array('e' => $resource->getTable('catalog_category_entity')),
                    'c.entity_id = e.entity_id',
                    array()
                );

            $connection->query(
                $connection->insertFromSelect(
                    $select,
                    $resource->getTable('catalog_category_product'),
                    array('category_id', 'product_id'),
                    1
                )
            );

            //Remove product from old categories
            $selectToDelete = $connection->select()
                ->from(
                    array(
                        'c' => $resource->getTable('pimgento_entities')
                    ),
                    array()
                )
                ->joinInner(
                    array('p' => $tmpTable),
                    '!FIND_IN_SET(`c`.`code`, `p`.`categories`) AND `c`.`import` = "category"',
                    array(
                        'category_id' => 'c.entity_id',
                        'product_id'  => 'p._entity_id'
                    )
                )
                ->joinInner(
                    array('e' => $resource->getTable('catalog_category_entity')),
                    'c.entity_id = e.entity_id',
                    array()
                );

            $connection->delete($resource->getTable('catalog_category_product'),
                '(category_id, product_id) IN (' . $selectToDelete->assemble() . ')');
        }
    }

    /**
     * Init Stock
     */
    public function initStock()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $websiteId = $this->_helperConfig->getDefaultScopeId();

        $values = array(
            'product_id' => '_entity_id',
            'stock_id' => new Expr(1),
            'qty' => new Expr(0),
            'is_in_stock' => new Expr(0),
            'low_stock_date' => new Expr('NULL'),
            'stock_status_changed_auto' => new Expr(0),
            'website_id' => new Expr($websiteId),
        );

        $select = $connection->select()->from($tmpTable, $values);

        $connection->query(
            $connection->insertFromSelect(
                $select,
                $resource->getTable('cataloginventory_stock_item'),
                array_keys($values),
                AdapterInterface::INSERT_IGNORE
            )
        );
    }

    /**
     * Set Url Rewrite
     */
    public function setUrlRewrite()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $stores = array_merge(
            $this->_helperConfig->getStores(['lang']), // en_US
            $this->_helperConfig->getStores(['lang', 'channel_code']) // en_US-channel
        );

        $this->_urlRewriteHelper->createUrlTmpTable();

        $columns = [];

        foreach ($stores as $local => $affected) {
            if ($connection->tableColumnExists($tmpTable, 'url_key-' . $local)) {
                foreach ($affected as $store) {
                    $columns[$store['store_id']] = 'url_key-' . $local;
                }
            }
        }

        if (!count($columns)) {
            foreach ($stores as $local => $affected) {
                foreach ($affected as $store) {
                    $columns[$store['store_id']] = 'url_key';
                }
            }
        }

        foreach ($columns as $store => $column) {
            if ($store == 0) {
                continue;
            }

            $duplicates = $connection->fetchCol(
                $connection->select()
                    ->from($tmpTable, [$column])
                    ->group($column)
                    ->having('COUNT(*) > 1')
            );

            foreach ($duplicates as $urlKey) {
                if ($urlKey) {
                    $connection->update(
                        $tmpTable,
                        [$column => new Expr('CONCAT(`' . $column . '`, "-", `sku`)')],
                        ['`' . $column . '` = ?' => $urlKey]
                    );
                }
            }

            $this->_entities->setValues(
                $this->getCode(),
                $resource->getTable('catalog_product_entity'),
                ['url_key' => $column],
                4,
                $store,
                1
            );

            $this->_urlRewriteHelper->rewriteUrls(
                $this->getCode(),
                $store,
                $column,
                $this->_scopeConfig->getValue('catalog/seo/product_url_suffix')
            );

        }


        $this->_urlRewriteHelper->dropUrlRewriteTmpTable();
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
            \Magento\PageCache\Model\Cache\Type::TYPE_IDENTIFIER
        );

        foreach ($types as $type) {
            $this->_cacheTypeList->cleanType($type);
        }

        $this->setMessage(
            __('Cache cleaned for: %1', join(', ', $types))
        );
    }

    /**
     * Clean the media folder
     */
    public function cleanMediaFolder()
    {
        $this->_media->cleanMediaFolder();
    }

    /**
     * Set related, up-sell and cross-sell
     */
    public function setRelated()
    {
        $this->_related->setCode($this->getCode());

        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $related = array();

        // Product relations
        if ($connection->tableColumnExists($tmpTable, 'RELATED-products')) {
            $related[] = array(
                'type_id' => Link::LINK_TYPE_RELATED,
                'column'  => 'RELATED-products',
            );
        }
        if ($connection->tableColumnExists($tmpTable, 'UPSELL-products')) {
            $related[] = array(
                'type_id' => Link::LINK_TYPE_UPSELL,
                'column'  => 'UPSELL-products',
            );
        }
        if ($connection->tableColumnExists($tmpTable, 'X_SELL-products')) {
            $related[] = array(
                'type_id' => Link::LINK_TYPE_CROSSSELL,
                'column'  => 'X_SELL-products',
            );
        }
        if ($connection->tableColumnExists($tmpTable, 'CROSSSELL-products')) {
            $related[] = array(
                'type_id' => Link::LINK_TYPE_CROSSSELL,
                'column'  => 'CROSSSELL-products',
            );
        }

        // Product group relations
        if ($connection->tableColumnExists($tmpTable, 'RELATED-groups')) {
            $related[] = array(
                'type_id' => Link::LINK_TYPE_RELATED,
                'column'  => 'RELATED-groups',
            );
        }
        if ($connection->tableColumnExists($tmpTable, 'UPSELL-groups')) {
            $related[] = array(
                'type_id' => Link::LINK_TYPE_UPSELL,
                'column'  => 'UPSELL-groups',
            );
        }
        if ($connection->tableColumnExists($tmpTable, 'X_SELL-groups')) {
            $related[] = array(
                'type_id' => Link::LINK_TYPE_CROSSSELL,
                'column'  => 'X_SELL-groups',
            );
        }
        if ($connection->tableColumnExists($tmpTable, 'CROSSSELL-groups')) {
            $related[] = array(
                'type_id' => Link::LINK_TYPE_CROSSSELL,
                'column'  => 'CROSSSELL-groups',
            );
        }

        $this->_related->relatedCreateTmpTables();
        foreach ($related as $type) {
            $this->_related->relatedImportColumn($type);
        }
        $this->_related->relatedDropTmpTables();
    }

    /**
     * Import the medias
     */
    public function importMedia()
    {
        $enabled = $this->_scopeConfig->getValue('pimgento/image/enabled');

        if (!$enabled) {
            $this->setMessage(
                __('Media importation is disabled (Stores > Configuration > Catalog > Pimgento > Image)')
            );
        } else {
            $this->_media->setCode($this->getCode());

            $this->_mediaHelper->initHelper(dirname($this->getFileFullPath()));

            $connection = $this->_entities->getResource()->getConnection();
            $tmpTable   = $this->_entities->getTableName($this->getCode());

            $tableColumns = array_keys($connection->describeTable($tmpTable));
            $fields       = $this->_mediaHelper->getFields();

            $this->_media->mediaCreateTmpTables();
            foreach ($fields as $field) {
                foreach ($field['columns'] as $position => $column) {
                    if (in_array($column, $tableColumns)) {
                        $this->_media->mediaPrepareValues($column, $field['attribute_id'], $position);
                    }
                }
            }

            $this->_media->mediaCleanValues();
            $this->_media->mediaRemoveUnknownFiles();
            $this->_media->mediaCopyFiles();
            $this->_media->mediaUpdateDataBase();
            $this->_media->mediaDropTmpTables();
        }
    }
}
