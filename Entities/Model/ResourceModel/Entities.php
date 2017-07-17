<?php

namespace Pimgento\Entities\Model\ResourceModel;

use \Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use \Magento\Framework\Model\ResourceModel\Db\Context;
use \Magento\Framework\Stdlib\DateTime\DateTime;
use \Magento\Framework\Model\AbstractModel;
use \Zend_Db_Expr as Expr;

class Entities extends AbstractDb
{

    const IGNORE_VALUE='!ignore!';

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $_date;

    /**
     * Construct
     *
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param string|null $resourcePrefix
     */
    public function __construct(Context $context, DateTime $date, $resourcePrefix = null)
    {
        parent::__construct($context, $resourcePrefix);
        $this->_date = $date;
    }

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('pimgento_entities', 'id');
    }

    /**
     * Process post data before saving
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _beforeSave(AbstractModel $object)
    {
        if ($object->isObjectNew()) {
            $object->setCreatedAt($this->_date->gmtDate());
        }

        return parent::_beforeSave($object);
    }

    /**
     * Perform actions before object delete
     *
     * @param \Magento\Framework\Model\AbstractModel|\Magento\Framework\DataObject $object
     * @return $this
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _beforeDelete(\Magento\Framework\Model\AbstractModel $object)
    {
        if (!$object->getId() && $object->getEntityId() && $object->getImport()) {
            $connection = $this->getConnection();

            $objectId = $connection->fetchOne(
                $connection->select()
                    ->from($this->getMainTable(), array('id'))
                    ->where('import = ?', $object->getImport())
                    ->where('entity_id = ?', $object->getEntityId())
            );

            if ($objectId) {
                $object->setId($objectId);
            }
        }

        return parent::_beforeDelete($object);
    }

    /**
     * Drop temporary table
     *
     * @param string $tableName
     * @return $this
     */
    public function dropTable($tableName)
    {
        $connection = $this->getConnection();

        $connection->resetDdlCache($tableName);
        $connection->dropTable($tableName);

        return $this;
    }

    /**
     * Create table
     *
     * @param array $fields
     * @param string $tableName
     * @return $this
     */
    public function createTable($fields, $tableName)
    {
        $connection = $this->getConnection();

        /* Delete table if exists */
        $this->dropTable($tableName);

        /* Create new table */
        $table = $connection->newTable($tableName);

        foreach ($fields as $field) {
            if ($field) {
                $column = $this->formatColumn($field);
                $table->addColumn(
                    $column,
                    \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    null,
                    [],
                    $column
                );
            }
        }

        $table->addColumn(
            '_entity_id',
            \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
            11,
            [],
            'Entity Id'
        );

        $table->addIndex(
            'UNIQUE_ENTITY_ID',
            '_entity_id',
            ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
        );

        $table->addColumn(
            '_is_new',
            \Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT,
            1,
            ['default' => 0],
            'Is New'
        );

        $connection->createTable($table);

        return $this;
    }

    /**
     * Execute Load data infile
     *
     * @param string $file
     * @param string $tableName
     * @param string $fieldsTerminated
     * @param string $linesTerminated
     * @param bool $local
     * @return int
     */
    public function loadDataInfile($file, $tableName, $fieldsTerminated = ';', $linesTerminated = '\n', $local = true)
    {
        $connection = $this->getConnection();

        $tmpFile = '/tmp/' . basename($file);

        if (is_file($tmpFile)) {
            unlink($tmpFile);
        }

        copy($file, $tmpFile);

        $query = "LOAD DATA" . ($local ? ' LOCAL' : '') . " INFILE '" . $tmpFile . "' REPLACE
              INTO TABLE " . $tableName . "
              CHARACTER SET UTF8
              FIELDS TERMINATED BY '" . $fieldsTerminated . "'
              OPTIONALLY ENCLOSED BY '\"'
              LINES TERMINATED BY '" . $linesTerminated . "'
              IGNORE 1 LINES;";

        $connection->query($query);

        if (is_file($tmpFile)) {
            unlink($tmpFile);
        }

        return $connection->fetchOne(
            $connection->select()->from($tableName, array(new Expr('COUNT(*)')))
        );
    }

    /**
     * Execute insert by rows
     *
     * @param string $file
     * @param string $tableName
     * @param string $fieldsTerminated
     * @param string $fieldsEnclosure
     *
     * @return int
     *
     * @throws \Exception
     */
    public function insertByRows($file, $tableName, $fieldsTerminated = ';', $fieldsEnclosure = '"')
    {
        if (!file_exists($file)) {
            throw new \Exception(__("%s does not exist.", $file));
        }

        if (!is_readable($file)) {
            throw new \Exception(__("Unable to read %s.", $file));
        }

        $fileHandle = fopen($file, "r");
        if ($fileHandle === false) {
            throw new \Exception(__("Unable to open %s.", $file));
        }

        $fileSize = filesize($file);
        if ($fileSize == 0) {
            fclose($fileHandle);
            throw new \Exception(__("Unable to open %s.", $file));
        }

        $columnNames  = [];
        $columnValues = [];
        $rowCount = 0;

        $connection = $this->getConnection();

        while (($csvLine = fgetcsv($fileHandle, null, $fieldsTerminated, $fieldsEnclosure)) !== false) {
            $rowCount++;

            if ($rowCount == 1) {
                // Get column names as first row - assumes first row always has this data
                foreach ($csvLine as $key => $value) {
                    array_push($columnNames, $value);
                }
                continue;
            }

            // Build column => value map for insert
            foreach ($csvLine as $key => $value) {
                if (!array_key_exists($key, $columnNames)) {
                    throw new \Exception('The line #'.$rowCount.' has too many columns');
                }

                $columnValues[$rowCount][$columnNames[$key]] = $value;
            }

            if ($rowCount % 1000 == 0) {
                // Insert our row into the tmp table
                $connection->insertMultiple($tableName, $columnValues);
                $columnValues = array();
            }
        }

        if (count($columnValues) > 0) {
            $connection->insertMultiple($tableName, $columnValues);
        }

        fclose($fileHandle);

        return $connection->fetchOne(
            $connection->select()->from($tableName, array(new Expr('COUNT(*)')))
        );
    }

    /**
     * Match Magento Id with code
     *
     * @param string $tableName
     * @param string $pimKey
     * @param string $entityTable
     * @param string $entityKey
     * @param string $import
     * @param string $prefix
     * @return $this
     */
    public function matchEntity($tableName, $pimKey, $entityTable, $entityKey, $import, $prefix = null)
    {
        $connection = $this->getConnection();

        $connection->delete($tableName, array($pimKey . ' = ?' => ''));

        $pimgentoTable = $connection->getTableName('pimgento_entities');
        $entityTable   = $connection->getTableName($entityTable);

        if ($entityKey == 'entity_id') {
            $entityKey = $this->getColumnIdentifier($entityTable);
        }

        /* Update entity_id column from pimgento_entities table */
        $connection->query('
            UPDATE `' . $tableName . '` t
            SET `_entity_id` = (
                SELECT `entity_id` FROM `' . $pimgentoTable . '` c
                WHERE ' . ($prefix ? 'CONCAT(t.`' . $prefix . '`, "_", t.`' . $pimKey . '`)' : 't.`' . $pimKey . '`') . ' = c.`code`
                    AND c.`import` = "' . $import . '"
            )
        ');

        /* Set entity_id for new entities */
        $query = $connection->query('SHOW TABLE STATUS LIKE "' . $entityTable . '"');
        $row = $query->fetch();

        $connection->query('SET @id = ' . (int)$row['Auto_increment']);
        $values = array(
            '_entity_id' => new Expr('@id := @id + 1'),
            '_is_new'    => new Expr('1'),
        );
        $connection->update($tableName, $values, '_entity_id IS NULL');

        /* Update pimgento_entities table with code and new entity_id */
        $select = $connection->select()
            ->from(
                $tableName,
                array(
                    'import'     => new Expr("'" . $import . "'"),
                    'code'       => $prefix ? new Expr('CONCAT(`' . $prefix . '`, "_", `' . $pimKey . '`)') : $pimKey,
                    'entity_id'  => '_entity_id'
                )
            )->where('_is_new = ?', 1);

        $connection->query(
            $connection->insertFromSelect($select, $pimgentoTable, array('import', 'code', 'entity_id'), 2)
        );

        /* Update entity table auto increment */
        $count = $connection->fetchOne(
            $connection->select()->from($tableName, array(new Expr('COUNT(*)')))->where('_is_new = ?', 1)
        );
        if ($count) {
            $maxCode = $connection->fetchOne(
                $connection->select()
                    ->from($pimgentoTable, new Expr('MAX(`entity_id`)'))
                    ->where('import = ?', $import)
            );
            $maxEntity = $connection->fetchOne(
                $connection->select()
                    ->from($entityTable, new Expr('MAX(`' . $entityKey . '`)'))
            );

            $connection->query(
                'ALTER TABLE `' . $entityTable . '` AUTO_INCREMENT = ' . (max((int)$maxCode, (int)$maxEntity) + 1)
            );
        }

        return $this;
    }

    /**
     * Set values to attributes
     *
     * @param string $tableName
     * @param string $entityTable
     * @param array  $values
     * @param int    $entityTypeId
     * @param int    $storeId
     * @param int    $mode
     * @return $this
     */
    public function setValues($tableName, $entityTable, $values, $entityTypeId, $storeId, $mode = 1)
    {
        $connection = $this->getConnection();


        $ignoreValue  = self::IGNORE_VALUE;

        foreach ($values as $code => $value) {

            if (($attribute = $this->getAttribute($code, $entityTypeId))) {
                if ($attribute['backend_type'] !== 'static') {
                    $backendType = $attribute['backend_type'];

                    $identifier = $this->getColumnIdentifier($entityTable . '_' . $backendType);


                    $columnName = $value;
                    $existColumn = $connection->tableColumnExists($tableName, $value);
                    if ($existColumn) {
                        $value = new Expr('IF(`' . $value . '` <> "", `' . $value . '`, NULL)');
                    }

                    $select = $connection->select()
                        ->from(
                            $tableName,
                            array(
                                'attribute_id'   => new Expr($attribute['attribute_id']),
                                'store_id'       => new Expr($storeId),
                                $identifier      => '_entity_id',
                                'value'          => $value,
                            )
                        );


                    if($existColumn) {
                        $select->where("`{$columnName}` <> ?", $ignoreValue);
                    }

                    $insert = $connection->insertFromSelect(
                        $select,
                        $connection->getTableName($entityTable . '_' . $backendType),
                        array('attribute_id', 'store_id', $identifier, 'value'),
                        $mode
                    );

                    $connection->query($insert);

                    if ($attribute['backend_type'] == 'datetime') {
                        $values = array(
                            'value' => new Expr('NULL'),
                        );
                        $where = array(
                            'value = ?' => '0000-00-00 00:00:00'
                        );
                        $connection->update(
                            $connection->getTableName($entityTable . '_' . $backendType), $values, $where
                        );
                    }
                }
            }
        }

        return $this;
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
        $connection = $this->getConnection();

        if ($connection->tableColumnExists($tableName, $source)) {
            $connection->addColumn($tableName, $target, 'TEXT');
            $connection->update(
                $tableName, array($target => new Expr('`' . $source . '`'))
            );
        }

        return $this;
    }

    /**
     * Retrieve attribute
     *
     * @param string $code
     * @param int    $entityTypeId
     * @return bool|array
     */
    public function getAttribute($code, $entityTypeId)
    {
        $connection = $this->getConnection();

        $attribute = $connection->fetchRow(
            $connection->select()
                ->from($connection->getTableName('eav_attribute'), array('attribute_id', 'backend_type'))
                ->where('entity_type_id = ?', $entityTypeId)
                ->where('attribute_code = ?', $code)
                ->limit(1)
        );
        return count($attribute) ? $attribute : false;
    }

    /**
     * Format column name
     *
     * @param string $column
     * @return string
     */
    protected function formatColumn($column)
    {
        return trim(str_replace(PHP_EOL, '', preg_replace('/\s+/', ' ', trim($column))), '""');
    }

    /**
     * Retrieve if row id column exists
     *
     * @param string $table
     * @param string $identifier
     * @return string
     */
    public function getColumnIdentifier($table, $identifier = 'entity_id')
    {
        $connection = $this->getConnection();

        if ($connection->tableColumnExists($table, 'row_id')) {
            $identifier = 'row_id';
        }

        return $identifier;
    }

}