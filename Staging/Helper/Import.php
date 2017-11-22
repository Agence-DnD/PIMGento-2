<?php


namespace Pimgento\Staging\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Staging\Model\VersionManager;
use Pimgento\Entities\Model\Entities;
use \Zend_Db_Expr as Expr;

/**
 * Class to help import products & categories with stage module enabled.
 *
 * @author    de Cramer Oliver<oldec@smile.fr>
 * @copyright 2017 Smile
 * @package Pimgento\Staging\Helper
 */
class Import extends AbstractHelper
{
    /**
     * @var Config
     */
    protected $configHelper;

    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param Config $configHelper
     */
    public function __construct(Context $context, \Pimgento\Staging\Helper\Config $configHelper)
    {
        $this->configHelper = $configHelper;

        parent::__construct($context);
    }

    /**
     * Add required data columns on the temporary table.
     *
     * @param AdapterInterface $connection
     * @param string $tmpTable
     */
    public function addRequiredData(AdapterInterface $connection, $tmpTable)
    {
        if ($this->configHelper->isCatalogStagingModulesEnabled()) {
            $connection->addColumn($tmpTable, '_row_id', 'INT(11)');
            $connection->addColumn($tmpTable, 'created_in', 'INT(11)');
            $connection->addColumn($tmpTable, 'updated_in', 'INT(11)');
            $connection->addColumn($tmpTable, '_new_entity', 'SMALLINT(1) DEFAULT 0');
        }
    }

    /**
     * Update dates in the temporary table with unix timestamp.
     *
     * @param AdapterInterface $connection
     * @param $tmpTable
     * @param $columns
     */
    public function updateDates(AdapterInterface $connection, $tmpTable, $columns)
    {
        $set = [];
        foreach ($columns as $origin => $to) {
            $set[] = "$to = UNIX_TIMESTAMP(STR_TO_DATE(t.$origin,'%Y-%m-%d'))";
        }

        $query = "UPDATE $tmpTable t
                SET " . implode(', ', $set) . "
            ";
        $connection->query($query);

        // Drop the original data not to have to bother with it.
        foreach ($columns as $origin => $to) {
            $connection->dropColumn($tmpTable, $origin);
        }

        // Put default values for now for products without version specification.
        $connection->query("UPDATE $tmpTable t SET created_in = 1 WHERE created_in = 0");
        $connection->query(
            "UPDATE $tmpTable t SET updated_in = " . VersionManager::MAX_VERSION . " WHERE updated_in = 0"
        );
    }

    /**
     * Check the dates that were given in the csv file to be sure they can be imported.
     *
     * The function returns the error message if there is one, and null if there isn't one.
     *
     * @param AdapterInterface $connection
     * @param $tmpTable
     * @param $identifier
     * @return null|string
     */
    public function checkStageDates(AdapterInterface $connection, $tmpTable, $entityTable, $identifier)
    {
        /**
         * Check that only one stage of the product is being imported.
         */
        $select = $connection
            ->select()
            ->from($tmpTable, new Expr('count(*) as nb'))
            ->group('sku')
            ->having('nb > 1');
        $nb = $connection->fetchOne($select);

        if ($nb > 1) {
            return "You can't import 2 stages of the same product at the same time!";
        }

        /**
         * Check for incoherence in the created in and updated_in values.
         */
        $select = $connection
            ->select()
            ->from($tmpTable, $identifier)
            ->where('created_in >= updated_in AND updated_in != 0');

        $invalidId = $connection->fetchOne($select);
        if ($invalidId) {
            return "Error with '$invalidId' : 'from' date superior or equal to it's 'to' date !";
        }

        /**
         * Check that all version to be imported are for future use as we can't modify older versions.
         */
        $select = $connection
            ->select()
            ->from($tmpTable, $identifier)
            ->where('created_in < ' . time() . ' AND created_in > 1')
            ->limit(1);

        $invalidId = $connection->fetchOne($select);
        if ($invalidId) {
            return "Error with '$invalidId' : Trying to update an on going or past stage !";
        }

        /**
         * Check that for each from value we have a single to value.
         */
        $select = $connection
            ->select()
            ->from($tmpTable, new Expr('count(DISTINCT updated_in) as nb'))
            ->where('created_in != 0')
            ->group('created_in')
            ->having('nb > 1');
        $nb = $connection->fetchOne($select);

        if ($nb > 1) {
            return "Error you can't have 2 stage starting at the same time !";
        }

        /**
         * Check If all simple products of configurables have same dates.
         */
        if ($connection->tableColumnExists($tmpTable, 'groups')) {
            $select = $connection
                ->select()
                ->from($tmpTable, ['groups'])
                ->where('groups != ""')
                ->group('groups')
                ->having('count(DISTINCT created_in) > 1');

            $nb = $connection->fetchOne($select);

            if ($nb > 1) {
                return "You can't have different stages for the products of the same configurable !";
            }
        }

        /*
         * Check if created & updated dates are also usable with actual database. In order to do this we check for each
         * product that we import if they alread
         */
        $select = $connection
            ->select()
            ->from(['t' => $tmpTable], $identifier)
            ->joinInner(
                ['e' => $entityTable],
                'e.'. $identifier . ' = t. ' . $identifier,
                []
            )
            ->joinInner(
                ['u' => $connection->getTableName('staging_update')],
                'u.id = e.created_in',
                []
            )
            ->where('u.is_rollback IS NULL')
            ->where(
                '(u.id < t.created_in AND (u.rollback_id > t.created_in OR u.rollback_id IS NULL))
                OR (u.id < t.updated_in AND (u.rollback_id > t.updated_in OR u.rollback_id IS NULL))
                OR ((u.id BETWEEN t.created_in AND t.updated_in) AND u.id != t.created_in AND u.id != t.updated_in)
                OR (u.rollback_id IS NOT NULL
                    AND (u.rollback_id BETWEEN t.created_in AND t.updated_in) 
                    AND u.rollback_id != t.created_in AND u.rollback_id != t.updated_in
                )'
            );
        $select->setPart('disable_staging_preview', true);

        $invalidId = $connection->fetchOne($select);
        if ($invalidId) {
            return "Error with '$invalidId' : 'from' and 'to' dates intersects with existing stages !";
        }

        return null;
    }

    /**
     * Matching the row id's for all our entities using different rules depending on the staging mode.
     *
     * @param Entities $entities
     * @param string $entityTableCode
     * @param string $code
     * @param string $stagingMode
     */
    public function matchEntityRows(Entities $entities, $entityTableCode, $code, $stagingMode)
    {
        $connection = $entities->getResource()->getConnection();
        $tmpTable = $entities->getTableName($code);
        $entityTable = $entities->getResource()->getTable($entityTableCode);

        $startTime = time();

        switch ($stagingMode) {
            case Config::STAGING_MODE_LAST:
            case Config::STAGING_MODE_ALL:
                /**
                 * Update row id column.
                 * We are going to update the last version that was created if there is multiple versions
                 *
                 * In full mode we will have another step that will duplicate the content in all the stages.
                 */
                $connection->query(
                    'UPDATE `' . $tmpTable . '` t
                    SET `_row_id` = (
                        SELECT MAX(row_id) FROM  `' . $entityTable . '` c
                        WHERE c.entity_id = t._entity_id AND updated_in = ' . VersionManager::MAX_VERSION . '
                    )'
                );

                break;

            case Config::STAGING_MODE_CURRENT:
                /**
                 * Update row id column.
                 * We are going to update the current versions.
                 */
                $connection->query(
                    'UPDATE `' . $tmpTable . '` t
                    SET `_row_id` = (
                        SELECT MAX(row_id) FROM  `' . $entityTable . '` c
                        WHERE c.entity_id = t._entity_id 
                            AND ( ' . $startTime . ' BETWEEN created_in AND updated_in)
                    )'
                );

                break;

            case Config::STAGING_MODE_FULL:

                $this->createTemporaryStageTable($connection);

                /**
                 * Update row id column with simple rule of getting the rows that can just be updated.
                 */
                $connection->query(
                    'UPDATE `' . $tmpTable . '` t
                    SET `_row_id` = (
                        SELECT MAX(row_id) FROM  `' . $entityTable . '` c
                        WHERE c.entity_id = t._entity_id 
                            AND c.created_in = t.created_in 
                            AND c.updated_in = t.updated_in
                    )'
                );

                /**
                 * For all remaining products, either we are importing a new product or we are creating
                 * a new stage to an existing product.
                 */

                // First let's handle new products. Simply change created/updated values to default. There is no need
                // to create multiple versions.
                $connection->query(
                    'UPDATE `' . $tmpTable . '` t
                    SET `created_in` = 1, 
                        `updated_in` = ' . VersionManager::MAX_VERSION . ',
                        `_new_entity` = 1
                    WHERE t._entity_id NOT IN (SELECT entity_id FROM ' . $entityTable . ')'
                );

                // Let's handle all the products that needs to be updated.
                $this->setStageValues($connection, $tmpTable, $entityTable);
        }


        /**
         * For existing versions fetch version created_in & updated_in from database.
         */
        $connection->query(
            'UPDATE `' . $tmpTable . '` t
            INNER JOIN  `' . $entityTable . '` c ON c.row_id = t._row_id
            SET t.created_in = c.created_in, 
                t.updated_in = c.updated_in
            WHERE t.created_in is NULL'
        );

        /**
         * For new entities we need to put default created_in & updated_in values.
         */
        $connection->query(
            'UPDATE `' . $tmpTable . '` t
            SET `created_in` = 1, 
                `updated_in` = ' . VersionManager::MAX_VERSION . '
            WHERE t.created_in is NULL'
        );
    }

    /**
     * When importing a new version for an existing product we will need to update the current stage,
     * duplicate it in the future and also update the staging_update table. Prepare the temporary tables to
     * handle this.
     *
     * @param AdapterInterface $connection
     * @param $tmpTable
     * @param $entityTable
     */
    protected function setStageValues(AdapterInterface $connection, $tmpTable, $entityTable)
    {
        // Let's find the versions that needs modifying.
        // TODO need to join with staging_update as well to check that there are no issues.
        // TODO We are not handling well stages that finishes in MAX_VERSION. Those just need previous version
        // to be updated.
        $select = $connection->select()
            ->from(
                ['t' => $tmpTable],
                [
                    'entity_id' => '_entity_id',
                    'entity_updated_in' => 'created_in',
                    'new_entity_created_in' => 'updated_in'
                ]
            )->joinInner(
                ['e' => $entityTable],
                't._entity_id = e.entity_id',
                [
                    'row_id' => 'row_id',
                    'entity_created_in' => 'created_in',
                    'new_entity_updated_in' => 'updated_in'
                ]
            )->where('e.created_in <= t.created_in AND e.updated_in >= t.updated_in AND t._row_id IS NULL')
            // Prevent staging module preview that adds additional conditions
            ->setPart('disable_staging_preview', true);

        $query = $connection->query($select);

        $toUpdate = [];
        $toDuplicate= [];
        $entityIds = [];
        while ($row = $query->fetch()) {

            $toUpdate[] = [
                '_entity_id' => $row['entity_id'],
                '_row_id' => $row['row_id'],
                'created_in' => $row['entity_created_in'],
                'updated_in' => $row['entity_updated_in'],
            ];

            if ($row['new_entity_created_in'] != $row['new_entity_updated_in']) {
                $toDuplicate[] = [
                    '_entity_id' => $row['entity_id'],
                    '_row_id' => $row['row_id'],
                    'created_in' => $row['new_entity_created_in'],
                    'updated_in' => $row['new_entity_updated_in'],
                ];
            }

            $entityIds[] = $row['entity_id'];

            if (count($toUpdate) > 500) {
                $this->insertTemporaryStageValues($connection, $tmpTable, $toUpdate, $toDuplicate, $entityIds);
                $toUpdate = [];
                $toDuplicate= [];
                $entityIds = [];
            }
        }

        if (count($toUpdate) > 0) {
            $this->insertTemporaryStageValues($connection, $tmpTable, $toUpdate, $toDuplicate, $entityIds);
        }
    }

    /**
     * Populate the temporary files to allow update & duplication of existing stage informations.
     *
     * @param AdapterInterface $connection
     * @param $tmpTable
     * @param $toUpdate
     * @param $toDuplicate
     * @param $entityIds
     */
    protected function insertTemporaryStageValues(
        AdapterInterface $connection,
        $tmpTable,
        $toUpdate,
        $toDuplicate,
        $entityIds
    ) {
        $tableStageUpdate = $connection->getTableName('tmp_pimgento_entity_stage_update');
        $tableStageDuplicate = $connection->getTableName('tmp_pimgento_entity_stage_duplicate');

        $connection->insertArray($tableStageUpdate, array_keys($toUpdate[0]), $toUpdate);

        if (!empty($toDuplicate)) {
            $connection->insertArray($tableStageDuplicate, array_keys($toDuplicate[0]), $toDuplicate);
        }

        $connection->update(
            $tmpTable,
            ['_new_entity' => new Expr('0')],
            ['_entity_id IN (?)' => $entityIds]
        );
    }

    /**
     * Create temporary tables to handle the staging.
     *
     * @param AdapterInterface $connection
     */
    protected function createTemporaryStageTable(AdapterInterface $connection)
    {
        /**
         * Table with row id's whose created_in & updated_in needs to be updated.
         */
        $tableStageUpdate = $connection->getTableName('tmp_pimgento_entity_stage_update');
        /**
         * Table with new stages that needs to be created with the row to use to get the data from.
         */
        $tableStageDuplicate = $connection->getTableName('tmp_pimgento_entity_stage_duplicate');

        $connection->dropTable($tableStageUpdate);
        $connection->dropTable($tableStageDuplicate);

        $table = $connection->newTable($tableStageUpdate);
        $table->addColumn('_entity_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, 11, []);
        $table->addColumn('_row_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, 11, []);
        $table->addColumn('created_in', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, 11, []);
        $table->addColumn('updated_in', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, 11, []);
        $connection->createTable($table);

        $table = $connection->newTable($tableStageDuplicate);
        $table->addColumn('_entity_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, 11, []);
        $table->addColumn('_row_id', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, 11, []);
        $table->addColumn('created_in', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, 11, []);
        $table->addColumn('updated_in', \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER, 11, []);
        $connection->createTable($table);
    }

    public function dropTemporaryStageTable(AdapterInterface $connection)
    {
        $connection->dropTable($connection->getTableName('tmp_pimgento_entity_stage_update'));
        $connection->dropTable($connection->getTableName('tmp_pimgento_entity_stage_duplicate'));
    }

    /**
     * When staging mode is enabled we also need to fill up the sequence_product table.
     *
     * @param AdapterInterface $connection
     * @param string $sequenceTable
     * @param string $tmpTable
     */
    public function createEntitiesBefore(AdapterInterface $connection, $sequenceTable, $tmpTable)
    {
        if ($this->configHelper->isCatalogStagingModulesEnabled()) {
            $sequenceValues = ['sequence_value' => '_entity_id'];
            $parents = $connection->select()->from($tmpTable, $sequenceValues);
            $connection->query(
                $connection->insertFromSelect(
                    $parents,
                    $connection->getTableName($sequenceTable),
                    array_keys($sequenceValues),
                    1
                )
            );
        }
    }

    /**
     * Once the catalog_entity table has been filled, we need to get the row id's for all the new
     * versions so that we can insert the values after.
     *
     * @param AdapterInterface $connection
     * @param string $entityTableCode
     * @param string $tmpTable
     * @param string $stagingMode
     */
    public function createEntitiesAfter(AdapterInterface $connection, $entityTableCode, $tmpTable, $stagingMode)
    {

        if ($this->configHelper->isCatalogStagingModulesEnabled()) {
            $entityTable = $connection->getTableName($entityTableCode);

            $connection->query(
                'UPDATE `' . $tmpTable . '` t
                SET `_row_id` = (
                    SELECT MAX(row_id) FROM  `' . $entityTable . '` c
                    WHERE c.entity_id = t._entity_id
                )
                WHERE t._row_id IS NULL'
            );

            if ($stagingMode == Config::STAGING_MODE_FULL) {
                $tableStageUpdate = $connection->getTableName('tmp_pimgento_entity_stage_update');
                $tableStageDuplicate = $connection->getTableName('tmp_pimgento_entity_stage_duplicate');

                // We need to update some of the existing entity rows to update the created_in/updated_in dates.
                $select = $connection->select()
                    ->from(
                        ['t' => $tableStageUpdate],
                        [
                            'row_id' => '_row_id',
                            'entity_id' => '_entity_id',
                            'created_in',
                            'updated_in',
                            'updated_at' => new Expr('now()'),
                        ]
                    );

                $query = $connection->insertFromSelect(
                    $select,
                    $connection->getTableName('catalog_product_entity'),
                    ['row_id', 'entity_id', 'created_in', 'updated_in', 'updated_at'],
                    1
                );
                $connection->query($query);

                // We also need to create some new rows.
                $select = $connection->select()
                    ->from(
                        ['t' => $tableStageDuplicate],
                        [
                            'entity_id' => '_entity_id',
                            'created_in',
                            'updated_in',
                            'updated_at' => new Expr('now()'),
                            'created_at' => new Expr('now()')
                        ]
                    )->joinInner(
                        ['u' => $entityTable],
                        'u.entity_id = t._entity_id',
                        [
                            'attribute_set_id', 'type_id', 'sku', 'has_options', 'required_options'
                        ]
                    );

                $query = $connection->insertFromSelect(
                    $select,
                    $entityTableCode,
                    [
                        'entity_id',
                        'created_in',
                        'updated_in',
                        'updated_at',
                        'created_at',
                        'attribute_set_id',
                        'type_id', 'sku',
                        'has_options',
                        'required_options'
                    ]
                );
                $connection->query($query);

                // Need to edit & insert lines in staging_update.
                $stagingTable = $connection->getTableName('staging_update');
                $select = $connection->select()
                    ->from(
                        ['t' => $tmpTable],
                        [
                            'id'         => 'created_in',
                            'start_time' => new Expr('from_unixtime(created_in)'),
                            'name' =>
                                new Expr(
                                    'CONCAT(
                                        "Pimgento : ", 
                                        from_unixtime(created_in, GET_FORMAT(DATE,"ISO")), 
                                        " to ", 
                                        from_unixtime(updated_in, GET_FORMAT(DATE,"ISO")))'
                                ),
                            'rollback_id'
                                => new Expr('IF(updated_in = ' . VersionManager::MAX_VERSION . ', NULL, updated_in)'),
                            'is_rollback' => new Expr('NULL'),
                        ]
                    )->where('created_in != 1');
                $query = $connection->insertFromSelect(
                    $select,
                    $stagingTable,
                    ['id', 'start_time', 'name', 'rollback_id', 'is_rollback'],
                    1
                );
                $connection->query($query);

                $stagingTable = $connection->getTableName('staging_update');
                $select = $connection->select()
                    ->from(
                        ['t' => $tmpTable],
                        [
                            'id'         => 'updated_in',
                            'start_time' => new Expr('from_unixtime(updated_in)'),
                            'name' => new Expr('"Rollback for Pimgento"'),
                            'rollback_id' => new Expr("NULL"),
                            'is_rollback' => new Expr('1'),
                        ]
                    )->where('created_in != 1 AND updated_in != ' . VersionManager::MAX_VERSION);
                $query = $connection->insertFromSelect(
                    $select,
                    $stagingTable,
                    ['id', 'start_time', 'name', 'rollback_id', 'is_rollback'],
                    1
                );
                $connection->query($query);

            }
        }
    }

    /**
     * Query to get all entity rows(stages) that has not been updated by the current import.
     *
     * Used to duplicate the data in all the stages.
     *
     * @param AdapterInterface $connection
     * @param string $entityTable
     * @param string $tmpTable
     * @param string $joinCondition
     *
     * @return Select
     */
    public function getBaseStageDuplicationSelect(
        AdapterInterface $connection,
        $entityTable,
        $tmpTable,
        $joinCondition = 't._row_id != e.row_id'
    ) {
        return $connection->select()
            ->from(
                ['e' =>$entityTable],
                []
            )
            // For each row we didn't update of the entities we have updated.
            ->joinInner(
                ['t' => $tmpTable],
                't._entity_id = e.entity_id AND ' . $joinCondition,
                []
            )
            // Prevent staging module preview that adds additional conditions
            ->setPart('disable_staging_preview', true);
    }
}