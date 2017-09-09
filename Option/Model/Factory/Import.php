<?php

namespace Pimgento\Option\Model\Factory;

use \Pimgento\Import\Model\Factory;
use \Pimgento\Entities\Model\Entities;
use \Pimgento\Import\Helper\Config as helperConfig;
use \Magento\Framework\Event\ManagerInterface;
use \Magento\Framework\App\Cache\TypeListInterface;
use \Magento\Framework\Module\Manager as moduleManager;
use \Magento\Framework\App\Config\ScopeConfigInterface as scopeConfig;
use \Zend_Db_Expr as Expr;
use \Exception;

class Import extends Factory
{

    /**
     * @var Entities
     */
    protected $_entities;

    /**
     * @var TypeListInterface
     */
    protected $_cacheTypeList;

    /**
     * @param \Pimgento\Entities\Model\Entities $entities
     * @param \Pimgento\Import\Helper\Config $helperConfig
     * @param \Magento\Framework\Module\Manager $moduleManager
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param array $data
     */
    public function __construct(
        Entities $entities,
        helperConfig $helperConfig,
        moduleManager $moduleManager,
        scopeConfig $scopeConfig,
        ManagerInterface $eventManager,
        TypeListInterface $cacheTypeList,
        array $data = []
    )
    {
        parent::__construct($helperConfig, $eventManager, $moduleManager, $scopeConfig, $data);
        $this->_entities = $entities;
        $this->_cacheTypeList = $cacheTypeList;
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
;
        } else {
            $this->_entities->createTmpTableFromFile($file, $this->getCode(), array('code', 'attribute'));
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
        $this->_entities->matchEntity($this->getCode(), 'code', 'eav_attribute_option', 'option_id', 'attribute');
    }

    /**
     * Insert options
     */
    public function insertOptions()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $columns = array(
            'option_id'  => 'a._entity_id',
            'sort_order' => new Expr('"0"')
        );
        if ($connection->tableColumnExists($tmpTable, 'sort_order')) {
            $columns['sort_order'] = 'a.sort_order';
        }

        $options = $connection->select()
            ->from(array('a' => $tmpTable), $columns)
            ->joinInner(
                array('b' => $resource->getTable('pimgento_entities')),
                'a.attribute = b.code AND b.import = "attribute"',
                array(
                    'attribute_id' => 'b.entity_id'
                )
            );

        $connection->query(
            $connection->insertFromSelect(
                $options,
                $resource->getTable('eav_attribute_option'),
                array('option_id', 'sort_order', 'attribute_id'),
                1
            )
        );
    }

    /**
     * Insert Values
     */
    public function insertValues()
    {
        $resource = $this->_entities->getResource();
        $connection = $resource->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $stores = $this->_helperConfig->getStores('lang');

        foreach ($stores as $local => $data) {
            if ($connection->tableColumnExists($tmpTable, 'label-' . $local)) {
                foreach ($data as $store) {
                    $options = $connection->select()
                        ->from(
                            $tmpTable,
                            array(
                                'option_id' => '_entity_id',
                                'store_id'  => new Expr($store['store_id']),
                                'value'     => $store['store_id'] == 0 ? 'code' : 'label-' . $local
                            )
                        );

                    $connection->query(
                        $connection->insertFromSelect(
                            $options,
                            $resource->getTable('eav_attribute_option_value'),
                            array('option_id', 'store_id', 'value'),
                            1
                        )
                    );
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
