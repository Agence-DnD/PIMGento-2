<?php

namespace Pimgento\Category\Model\Factory;

use \Pimgento\Import\Model\Factory;
use \Pimgento\Entities\Model\Entities;
use \Pimgento\Import\Helper\Config as helperConfig;
use \Pimgento\Import\Helper\UrlRewrite as urlRewriteHelper;
use \Magento\Framework\Event\ManagerInterface;
use \Magento\Catalog\Model\Category;
use \Magento\Framework\App\Cache\TypeListInterface;
use \Magento\Framework\Module\Manager as moduleManager;
use \Magento\Framework\App\Config\ScopeConfigInterface as scopeConfig;
use \Magento\Staging\Model\VersionManager;
use \Zend_Db_Expr as Expr;
use \Exception;

class Import extends Factory
{

    /**
     * @var Entities
     */
    protected $_entities;

    /**
     * @var Category
     */
    protected $_category;

    /**
     * @var TypeListInterface
     */
    protected $_cacheTypeList;

    /**
     * @var urlRewriteHelper
     */
    protected $_urlRewriteHelper;

    /**
     * @param \Pimgento\Entities\Model\Entities $entities
     * @param \Pimgento\Import\Helper\Config $helperConfig
     * @param \Magento\Framework\Module\Manager $moduleManager
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Catalog\Model\Category $category
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param urlRewriteHelper $urlRewriteHelper
     * @param array $data
     */
    public function __construct(
        Entities $entities,
        helperConfig $helperConfig,
        moduleManager $moduleManager,
        scopeConfig $scopeConfig,
        ManagerInterface $eventManager,
        Category $category,
        TypeListInterface $cacheTypeList,
        urlRewriteHelper $urlRewriteHelper,
        array $data = []
    )
    {
        parent::__construct($helperConfig, $eventManager, $moduleManager, $scopeConfig, $data);
        $this->_entities = $entities;
        $this->_category = $category;
        $this->_cacheTypeList = $cacheTypeList;
        $this->_urlRewriteHelper = $urlRewriteHelper;
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
            $this->_entities->createTmpTableFromFile($file, $this->getCode(), array('code', 'parent'));
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
        $this->_entities->matchEntity($this->getCode(), 'code', 'catalog_category_entity', 'entity_id');
    }

    /**
     * Set categories Url Key
     */
    public function setUrlKey()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $stores = $this->_helperConfig->getStores('lang');

        foreach ($stores as $local => $affected) {

            $keys = [];

            if ($connection->tableColumnExists($tmpTable, 'label-' . $local)) {

                $connection->addColumn($tmpTable, 'url_key-' . $local, 'VARCHAR(255) NOT NULL DEFAULT ""');

                $select = $connection->select()
                    ->from($tmpTable, ['entity_id' => '_entity_id', 'name' => 'label-' . $local]);

                $updateUrlKeyConfig = $this->_scopeConfig->getValue('pimgento/category/update_url_key');

                if (!$updateUrlKeyConfig) {
                    $select->where('_is_new = ?', 1);
                }

                $query = $connection->query($select);

                while (($row = $query->fetch())) {
                    $urlKey = $this->_category->formatUrlKey($row['name']);

                    $finalKey = $urlKey;
                    $increment = 1;
                    while (in_array($finalKey, $keys)) {
                        $finalKey = $urlKey . '-' . $increment++;
                    }

                    $keys[] = $finalKey;

                    $connection->update(
                        $tmpTable, ['url_key-' . $local => $finalKey], ['_entity_id = ?' => $row['entity_id']]
                    );
                }

                if (!$updateUrlKeyConfig) {
                    $connection->update(
                        $tmpTable, ['url_key-' . $local => \Pimgento\Entities\Model\ResourceModel\Entities::IGNORE_VALUE], ['_is_new=?' => 0]
                    );
                }
            }
        }

    }

    /**
     * Set Categories structure
     */
    public function setStructure()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $connection->addColumn($tmpTable, 'level', 'INT(11) NOT NULL DEFAULT 0');
        $connection->addColumn($tmpTable, 'path', 'VARCHAR(255) NOT NULL DEFAULT ""');
        $connection->addColumn($tmpTable, 'parent_id', 'INT(11) NOT NULL DEFAULT 0');

        $stores = $this->_helperConfig->getStores('lang');

        $values = array(
            'level' => 1,
            'path' => new Expr('CONCAT(1, "/", `_entity_id`)'),
            'parent_id' => 1,
        );
        $connection->update($tmpTable, $values, 'parent = ""');

        $updateRewrite = array();

        foreach ($stores as $local => $affected) {
            if ($connection->tableColumnExists($tmpTable, 'url_key-' . $local)) {
                $connection->addColumn($tmpTable, '_url_rewrite-' . $local, 'VARCHAR(255) NOT NULL DEFAULT ""');
                $updateRewrite[] = 'c1.`_url_rewrite-' . $local . '` =
                    IF(c1.`url_key-' . $local . '` <> "", TRIM(BOTH "/" FROM CONCAT(c2.`_url_rewrite-' . $local . '`, "/", c1.`url_key-' . $local . '`)), "")';
            }
        }

        $depth = 10;
        for ($i = 1; $i <= $depth; $i++) {
            $connection->query('
                UPDATE `' . $tmpTable . '` c1
                INNER JOIN `' . $tmpTable . '` c2 ON c2.`code` = c1.`parent`
                SET ' . (!empty($updateRewrite) ? join(',', $updateRewrite) . ',' : '') . '
                    c1.`level` = c2.`level` + 1,
                    c1.`path` = CONCAT(c2.`path`, "/", c1.`_entity_id`),
                    c1.`parent_id` = c2.`_entity_id`
                WHERE c1.`level` <= c2.`level` - 1
            ');
        }
    }

    /**
     * Set categories position
     */
    public function setPosition()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $connection->addColumn($tmpTable, 'position', 'INT(11) NOT NULL DEFAULT 0');

        $query = $connection->query(
            $connection->select()
                ->from(
                    $tmpTable,
                    array(
                        'entity_id' => '_entity_id',
                        'parent_id' => 'parent_id',
                    )
                )
        );

        while (($row = $query->fetch())) {
            $position = $connection->fetchOne(
                $connection->select()
                    ->from(
                        $tmpTable,
                        array(
                            'position' => new Expr('MAX(`position`) + 1')
                        )
                    )
                    ->where('parent_id = ?', $row['parent_id'])
                    ->group('parent_id')
            );
            $values = array(
                'position' => $position
            );
            $connection->update($tmpTable, $values, array('_entity_id = ?' => $row['entity_id']));
        }
    }

    /**
     * Create category entities
     */
    public function createEntities()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        if ($connection->isTableExists($connection->getTableName('sequence_catalog_category'))) {
            $values = array(
                'sequence_value' => '_entity_id',
            );
            $parents = $connection->select()->from($tmpTable, $values);
            $connection->query(
                $connection->insertFromSelect(
                    $parents, $connection->getTableName('sequence_catalog_category'), array_keys($values), 1
                )
            );
        }

        $table = $connection->getTableName('catalog_category_entity');

        $values = array(
            'entity_id' => '_entity_id',
            'attribute_set_id' => new Expr(3),
            'parent_id' => 'parent_id',
            'updated_at' => new Expr('now()'),
            'path' => 'path',
            'position' => 'position',
            'level' => 'level',
            'children_count' => new Expr('0'),
        );

        if ($this->_entities->getColumnIdentifier($table) == 'row_id') {
            $values['row_id'] = '_entity_id';
            $values['created_in'] = new Expr(1);
            $values['updated_in'] = new Expr(VersionManager::MAX_VERSION);
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
    }

    /**
     * Set values to attributes
     */
    public function setValues()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $values = array(
            'is_active' => new Expr(1),
            'include_in_menu' => new Expr(1),
            'is_anchor' => new Expr(1),
            'display_mode' => new Expr('"PRODUCTS"'),
        );

        $this->_entities->setValues(
            $this->getCode(), $connection->getTableName('catalog_category_entity'), $values, 3, 0, 2
        );

        $stores = $this->_helperConfig->getStores('lang');

        foreach ($stores as $local => $affected) {
            if ($connection->tableColumnExists($tmpTable, 'label-' . $local)) {
                foreach ($affected as $store) {
                    $values = array(
                        'name' => 'label-' . $local,
                        'url_key' => 'url_key-' . $local,
                    );
                    $this->_entities->setValues(
                        $this->getCode(),
                        $connection->getTableName('catalog_category_entity'),
                        $values,
                        3,
                        $store['store_id']
                    );
                }
            }
        }
    }

    /**
     * Update Children Count
     */
    public function updateChildrenCount()
    {
        $connection = $this->_entities->getResource()->getConnection();

        $connection->query('
            UPDATE `' . $connection->getTableName('catalog_category_entity') . '` c SET `children_count` = (
                SELECT COUNT(`parent_id`) FROM (
                    SELECT * FROM `' . $connection->getTableName('catalog_category_entity') . '`
                ) tmp
                WHERE tmp.`path` LIKE CONCAT(c.`path`,\'/%\')
            )
        ');
    }

    /**
     * Set Url Rewrite
     */
    public function setUrlRewrite()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $stores = $this->_helperConfig->getStores('lang');
        $this->_urlRewriteHelper->createUrlTmpTable();

        foreach ($stores as $local => $affected) {

            $column = '_url_rewrite-' . $local;
            if ($connection->tableColumnExists($tmpTable, $column)) {
                foreach ($affected as $store) {

                    if ($store['store_id'] == 0) {
                        continue;
                    }

                    $this->_urlRewriteHelper->rewriteUrls(
                        $this->getCode(),
                        $store['store_id'],
                        $column,
                        $this->_scopeConfig->getValue('catalog/seo/category_url_suffix')
                    );
                }
            }

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

}