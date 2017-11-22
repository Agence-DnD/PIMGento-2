<?php

namespace Pimgento\Entities\Model;

use \Pimgento\Entities\Api\Data\EntitiesInterface;
use \Magento\Framework\DataObject\IdentityInterface;
use \Magento\Framework\Model\AbstractModel;
use \Exception;

class Entities extends AbstractModel implements EntitiesInterface, IdentityInterface
{

    /**
     * Prefix of model events names
     *
     * @var string
     */
    protected $_eventPrefix = 'pimgento_entities';

    /**
     * @var \Pimgento\Entities\Helper\Config
     */
    protected $_configHelper;

    /**
     * Cache tag
     */
    const CACHE_TAG = 'pimgento_entities';

    /**
     * Temporary table prefix
     */
    const TABLE_PREFIX = 'tmp';

    /**
     * Initialize resource model and config helper
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('Pimgento\Entities\Model\ResourceModel\Entities');

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_configHelper = $objectManager->get('Pimgento\Entities\Helper\Config');
    }

    /**
     * Create a temporary table
     *
     * @param array $columns
     * @param string $tableSuffix
     * @return $this
     */
    public function createTmpTable($columns, $tableSuffix)
    {
        $this->_getResource()->createTable($columns, $this->getTableName($tableSuffix));

        return $this;
    }

    /**
     * Create table from CSV file
     *
     * @param string $file
     * @param string $tableSuffix
     * @param array $required
     * @return $this
     * @throws Exception
     */
    public function createTmpTableFromFile($file, $tableSuffix, $required = array())
    {
        $columns = $this->getFileColumns($file);

        foreach ($required as $column) {
            if (!in_array($column, $columns)) {
                throw new Exception(__('Column %1 not found', $column));
            }
        }

        $this->createTmpTable($columns, $tableSuffix);

        return $this;
    }

    /**
     * Insert data from file into temporary table
     *
     * @param string $file
     * @param string $tableSuffix
     * @return int
     */
    public function insertDataFromFile($file, $tableSuffix)
    {
        $method =  $this->_configHelper->getInsertionMethod();
        switch ($method) {
            case \Pimgento\Entities\Helper\Config::INSERTION_METHOD_BY_ROWS:
                $result = $this->insertWithByRowsMethod($file, $tableSuffix);
                break;

            case \Pimgento\Entities\Helper\Config::INSERTION_METHOD_DATA_IN_FILE:
            default:
                $result = $this->insertWithDataInFileMethod($file, $tableSuffix);
                break;
        }

        return $result;
    }

    /**
     * Insert data from file into temporary table
     *
     * @param string $file
     * @param string $tableSuffix
     * @return int
     */
    protected function insertWithDataInFileMethod($file, $tableSuffix)
    {
        $local = $this->_configHelper->getLoadDataLocal() ? true : false;

        return $this->_getResource()->loadDataInfile(
            $file,
            $this->getTableName($tableSuffix),
            $this->_configHelper->getCsvConfig()['fields_terminated'],
            $this->_configHelper->getCsvConfig()['lines_terminated'],
            $local
        );
    }

    /**
     * Insert data by rows
     *
     * @param string $file
     * @param string $tableSuffix
     * @return int
     */
    protected function insertWithByRowsMethod($file, $tableSuffix)
    {
        return $this->_getResource()->insertByRows(
            $file,
            $this->getTableName($tableSuffix),
            $this->_configHelper->getCsvConfig()['fields_terminated'],
            $this->_configHelper->getCsvConfig()['fields_enclosure'],
            $this->_configHelper->getQueryNumber()
        );
    }

    /**
     * Match Magento Id with code
     *
     * @param string $tableSuffix
     * @param string $pimKey
     * @param string $entityTable
     * @param string $entityKey
     * @param string $prefix
     * @return $this
     */
    public function matchEntity($tableSuffix, $pimKey, $entityTable, $entityKey, $prefix = null)
    {
        $this->_getResource()->matchEntity(
            $this->getTableName($tableSuffix),
            $pimKey,
            $entityTable,
            $entityKey,
            $tableSuffix,
            $prefix
        );

        return $this;
    }

    /**
     * Set values to attributes
     *
     * @param string $tableSuffix
     * @param string $entityTable
     * @param array  $values
     * @param int    $entityTypeId
     * @param int    $storeId
     * @param int    $mode
     * @return $this
     */
    public function setValues($tableSuffix, $entityTable, $values, $entityTypeId, $storeId, $mode = 1)
    {
        $this->_getResource()
            ->setValues(
                $this->getTableName($tableSuffix),
                $entityTable,
                $values,
                $entityTypeId,
                $storeId,
                $mode
            );

        return $this;
    }

    /**
     * Retrieve column identifier
     *
     * @param string $table
     * @return string
     */
    public function getColumnIdentifier($table)
    {
        return $this->_getResource()->getColumnIdentifier($table);
    }

    /**
     * Copy column to an other
     *
     * @param string $tableName
     * @param string $source
     * @param string $target
     * @return $this
     */
    public function copyColumn($tableName, $source, $target)
    {
        $this->_getResource()->copyColumn($tableName, $source, $target);

        return $this;
    }

    /**
     * Drop temporary table
     *
     * @param string $tableSuffix
     * @return $this
     */
    public function dropTable($tableSuffix)
    {
        $this->_getResource()->dropTable($this->getTableName($tableSuffix));

        return $this;
    }

    /**
     * Retrieve temporary table name
     *
     * @param string $tableSuffix
     * @return string
     */
    public function getTableName($tableSuffix = null)
    {
        $fragments = array(
            self::TABLE_PREFIX,
            self::CACHE_TAG
        );

        if ($tableSuffix) {
            $fragments[] = $tableSuffix;
        }

        return $this->_getResource()->getTable(join('_', $fragments));
    }

    /**
     * Retrieve file columns
     *
     * @param string $file
     * @return array
     */
    public function getFileColumns($file)
    {
        $handle = fopen($file, 'r');
        $line = preg_replace("/\\r|\\n|\"|'/", "", fgets($handle));
        fclose($handle);

        $separator = $this->_configHelper->getCsvConfig()['fields_terminated'];

        return explode($separator, $line);
    }

    /**
     * Return unique ID(s) for each object in system
     *
     * @return array
     */
    public function getIdentities()
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }

    /**
     * Get ID
     *
     * @return int|null
     */
    public function getId()
    {
        return $this->getData(self::ID);
    }

    /**
     * Get code
     *
     * @return string
     */
    public function getCode()
    {
        return $this->getData(self::CODE);
    }

    /**
     * Get entity id
     *
     * @return int
     */
    public function getEntityId()
    {
        return $this->getData(self::ENTITY_ID);
    }

    /**
     * Get Import
     *
     * @return string
     */
    public function getImport()
    {
        return $this->getData(self::IMPORT);
    }

    /**
     * Get creation time
     *
     * @return string
     */
    public function getCreatedAt()
    {
        return $this->getData(self::CREATED_AT);
    }

    /**
     * Set ID
     *
     * @param int $id
     * @return $this
     */
    public function setId($id)
    {
        return $this->setData(self::ID, $id);
    }

    /**
     * Set code
     *
     * @param string $code
     * @return $this
     */
    public function setCode($code)
    {
        return $this->setData(self::CODE, $code);
    }

    /**
     * Set entity id
     *
     * @param int $entityId
     * @return $this
     */
    public function setEntityId($entityId)
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    /**
     * Set Import
     *
     * @param string $import
     * @return $this
     */
    public function setImport($import)
    {
        return $this->setData(self::IMPORT, $import);
    }

    /**
     * Set creation time
     *
     * @param string $createdAt
     * @return $this
     */
    public function setCreatedAt($createdAt)
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    /**
     * Get resource instance
     *
     * @return \Pimgento\Entities\Model\ResourceModel\Entities
     */
    protected function _getResource()
    {
        return parent::_getResource();
    }

}