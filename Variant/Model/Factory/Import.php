<?php

namespace Pimgento\Variant\Model\Factory;

use \Pimgento\Import\Model\Factory;
use \Pimgento\Entities\Model\Entities;
use \Pimgento\Import\Helper\Config as helperConfig;
use \Magento\Framework\Event\ManagerInterface;
use \Magento\Framework\App\Cache\TypeListInterface;
use \Magento\Eav\Model\Entity\Attribute\SetFactory;
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
        } else {
            $this->_entities->createTmpTableFromFile($file, $this->getCode(), array('code', 'axis'));
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
     * Remove columns from variant table
     */
    public function removeColumns()
    {
        $connection = $this->_entities->getResource()->getConnection();

        $except = array('code', 'axis');

        $variantTable = $connection->getTableName('pimgento_variant');

        $columns = array_keys($connection->describeTable($variantTable));

        foreach ($columns as $column) {
            if (in_array($column, $except)) {
                continue;
            }

            $connection->dropColumn($variantTable, $column);
        }
    }

    /**
     * Add columns to variant table
     */
    public function addColumns()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $except = array('code', 'axis', 'type', '_entity_id', '_is_new');

        $variantTable = $connection->getTableName('pimgento_variant');

        $columns = array_keys($connection->describeTable($tmpTable));

        foreach ($columns as $column) {
            if (in_array($column, $except)) {
                continue;
            }

            $connection->addColumn($variantTable, $this->_columnName($column), 'TEXT');
        }
    }

    /**
     * Add or update data in variant table
     */
    public function updateData()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $variantTable = $connection->getTableName('pimgento_variant');

        $variant = $connection->query(
            $connection->select()->from($tmpTable)
        );

        $attributes = $connection->fetchPairs(
            $connection->select()->from(
                $connection->getTableName('eav_attribute'), array('attribute_code', 'attribute_id')
            )
            ->where('entity_type_id = ?', 4)
        );

        $columns = array_keys($connection->describeTable($tmpTable));

        $values = [];
        $i = 0;
        $keys = [];
        while (($row = $variant->fetch())) {


            $values[$i] = [];

            foreach ($columns as $column) {

                if ($connection->tableColumnExists($variantTable, $this->_columnName($column))) {

                    $values[$i][$this->_columnName($column)] = $row[$column];

                    if ($column == 'axis') {
                        $axisAttributes = explode(',', $row['axis']);

                        $axis = array();

                        foreach ($axisAttributes as $code) {
                            if (isset($attributes[$code])) {
                                $axis[] = $attributes[$code];
                            }
                        }

                        $values[$i][$column] = join(',', $axis);
                    }

                    $keys = array_keys($values[$i]);
                }
            }
            $i++;

            /**
             * Write 500 values at a time.
             */
            if (count($values) > 500) {
                $connection->insertOnDuplicate($variantTable, $values, $keys);
                $values = [];
                $i = 0;
            }
        }

        if (count($values) > 0) {
            $connection->insertOnDuplicate($variantTable, $values, $keys);
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

    /**
     * Replace column name
     *
     * @param string $column
     * @return string
     */
    protected function _columnName($column)
    {
        $matches = array(
            'label' => 'name',
        );

        foreach ($matches as $name => $replace) {
            if (preg_match('/^'. $name . '/', $column)) {
                $column = preg_replace('/^'. $name . '/', $replace, $column);
            }
        }

        return $column;
    }

}