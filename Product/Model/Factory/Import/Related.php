<?php

namespace Pimgento\Product\Model\Factory\Import;

use \Pimgento\Import\Model\Factory;
use \Pimgento\Entities\Model\Entities;
use \Pimgento\Import\Helper\Config as helperConfig;
use \Magento\Framework\Event\ManagerInterface;
use \Magento\Framework\Module\Manager as moduleManager;
use \Magento\Framework\App\Config\ScopeConfigInterface as scopeConfig;
use \Magento\Framework\DB\Adapter\AdapterInterface;
use \Magento\Framework\DB\Ddl\Table;
use \Zend_Db_Expr as Expr;

class Related extends Factory
{

    /**
     * @var Entities
     */
    protected $_entities;

    /**
     * PHP Constructor
     *
     * @param \Pimgento\Import\Helper\Config                     $helperConfig
     * @param \Magento\Framework\Event\ManagerInterface          $eventManager
     * @param \Magento\Framework\Module\Manager                  $moduleManager
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Pimgento\Entities\Model\Entities                  $entities
     * @param array                                              $data
     */
    public function __construct(
        helperConfig $helperConfig,
        ManagerInterface $eventManager,
        moduleManager $moduleManager,
        scopeConfig $scopeConfig,
        Entities $entities,
        array $data = []
    ) {
        parent::__construct($helperConfig, $eventManager, $moduleManager, $scopeConfig, $data);

        $this->_entities = $entities;
    }

    /**
     * Create the temporary tables needed bby related product import
     *
     * @return void
     */
    public function relatedCreateTmpTables()
    {
        $connection   = $this->_entities->getResource()->getConnection();
        $tableNumber  = $this->_entities->getTableName('numbers');;
        $tableRelated = $this->_entities->getTableName('related');;

        $connection->dropTable($tableNumber);
        $connection->dropTable($tableRelated);

        /* Table numbers */
        $table = $connection->newTable($tableNumber);
        $table->addColumn('n', Table::TYPE_INTEGER, 11, []);
        $connection->createTable($table);

        /* Table related */
        $table = $connection->newTable($tableRelated);
        $table->addColumn('parent_sku', Table::TYPE_TEXT, 255, []);
        $table->addColumn('parent_id', Table::TYPE_INTEGER, 11, []);
        $table->addColumn('child_sku', Table::TYPE_TEXT, 255, []);
        $table->addColumn('child_id', Table::TYPE_INTEGER, 11, []);
        $connection->createTable($table);

        $values = [];
        for ($k = 0; $k < 10000; $k++) {
            $values[] = ['n' => $k + 1];
        }
        $connection->insertMultiple($tableNumber, $values);
    }

    /**
     * Drop the temporary tables needed bby related product import
     *
     * @return void
     */
    public function relatedDropTmpTables()
    {
        $connection   = $this->_entities->getResource()->getConnection();
        $tableNumber  = $this->_entities->getTableName('numbers');
        $tableRelated = $this->_entities->getTableName('related');

        $connection->dropTable($tableNumber);
        $connection->dropTable($tableRelated);
    }

    /**
     * Manage one related column
     *
     * @param array $type
     *
     * @return bool
     */
    public function relatedImportColumn($type)
    {
        $connection = $this->_entities->getResource()->getConnection();

        $tmpTable     = $this->_entities->getTableName($this->getCode());
        $tableNumber  = $this->_entities->getTableName('numbers');;
        $tableRelated = $this->_entities->getTableName('related');;
        $tableProduct = $connection->getTableName('catalog_product_entity');

        $column = $type['column'];

        $ids = $this->getMaxAndMinIds($column);
        if ($ids['max'] < 1) {
            return false;
        }

        // We must do this step by step because of a mysql usage limitation
        $step = 1000;
        $min = $ids['min'] + $step;
        $max = $ids['max'] + $step;
        for ($limit = $min; $limit <= $max; $limit += $step) {
            // Transform one row for multiple links => one row for one link
            $select = $connection->select()
                ->from(
                    ['t' => $tmpTable],
                    ['parent_sku' => 't.sku']
                )->joinInner(
                    ['n' => $tableNumber],
                    "
                        `t`.`$column` <> ''
                        AND `t`.`_entity_id` <= $limit
                        AND (char_length(t.`$column`) - char_length(replace(t.`$column`, ',', ''))) >= n.n-1
                    ",
                    ['child_sku' => new Expr("substring_index(substring_index(t.`$column`, ',', n.n), ',', -1)")]
                );

            $query = $connection->insertFromSelect(
                $select,
                $tableRelated,
                ['parent_sku', 'child_sku'],
                AdapterInterface::INSERT_ON_DUPLICATE
            );

            $connection->query($query);
            $connection->update(
                $tmpTable,
                [$column => ''],
                ["_entity_id <= $limit" ]
            );
        }

        // Get the product ids for parents
        $query = $connection->select()
            ->from(false, ['parent_id' => 'p.' . $this->_entities->getColumnIdentifier($tableProduct)])
            ->joinLeft(
                ['p' => $tableProduct],
                'r.parent_sku = p.sku',
                []
            );

        $connection->query(
            $connection->updateFromSelect($query, ['r' => $tableRelated])
        );

        // Get the product ids for links
        $query = $connection->select()
            ->from(false, ['child_id' => 'p.' . $this->_entities->getColumnIdentifier($tableProduct)])
            ->joinLeft(
                ['p' => $tableProduct],
                'r.child_sku = p.sku',
                []
            );

        $connection->query(
            $connection->updateFromSelect($query, ['r' => $tableRelated])
        );

        // Delete bad links
        $connection->delete($tableRelated, 'child_id IS NULL or parent_id IS NULL');

        // Save the links
        $select = $connection->select()
            ->from(
                ['l' => $tableRelated],
                [
                    'product_id'        => 'l.parent_id',
                    'linked_product_id' => 'l.child_id',
                    'link_type_id'      => new Expr($type['type_id'])
                ]
            );

	    $this->deleteRemovedRelations($select, $type['type_id']);

        $query = $connection->insertFromSelect(
            $select,
            $connection->getTableName('catalog_product_link'),
            ['product_id', 'linked_product_id', 'link_type_id'],
            AdapterInterface::INSERT_ON_DUPLICATE
        );
        $connection->query($query);

        $connection->delete($tableRelated);

        return true;
    }

    /**
     * Get the min and the max of the product entity_id where a column is not empty
     *
     * @param string $column
     *
     * @return int[]
     */
    public function getMaxAndMinIds($column)
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $select = $connection->select()
            ->from(
                ['t' => $tmpTable],
                [
                    'min_id' => new Expr('MIN(_entity_id)'),
                    'max_id' => new Expr('MAX(_entity_id)'),
                ]
            )
            ->where("`$column` <> ''");

        $ids = $connection->fetchAll($select);
        $ids = [
            'min' => (int) $ids[0]['min_id'],
            'max' => (int) $ids[0]['max_id'],
        ];

        return $ids;
    }

	/**
	 * Remove previous relations that are not existing anymore in import file
	 *
	 * @param string $tmpTableSelect - Select statement to retrieve new data from previously created tmp table
	 * @param int $typeId
	 */
	private function deleteRemovedRelations($tmpTableSelect, $typeId)
	{
		// No new entities - nothing to do
		$importIds = $this->getImportEntities();
		if (empty($importIds)) {
			return;
		}

		$connection = $this->_entities->getResource()->getConnection();
		$linkTable = $connection->getTableName('catalog_product_link');

		// Only delete removed relations for import products
		$select = $connection->select()
			->from($linkTable)
			->where("link_type_id = $typeId")
			->where(sprintf('product_id IN (%s)', implode(',', $importIds)));

		$oldRelations = $connection->query($select)->fetchAll();
		$newRelations = $connection->query($tmpTableSelect)->fetchAll();
		$deleted = [];

		foreach ($oldRelations as $old) {

			$found = false;
			foreach ($newRelations as $new) {

				if ($new['product_id'] == $old['product_id']
					&& $new['linked_product_id'] == $old['linked_product_id']
					&& $new['link_type_id'] == $old['link_type_id']) {

					$found = true;
				}
			}

			if (!$found) {
				$deleted[] = $old['link_id'];
			}
		}

		if (count($deleted) > 0) {
			$sql = sprintf('DELETE FROM `%s` WHERE link_id IN (%s)', $linkTable, implode(',', $deleted));
			$connection->query($sql);
		}
	}

	/**
	 * Fetch ids of entities to be imported
	 *
	 * @return int[]
	 */
	private function getImportEntities()
	{
		$connection = $this->_entities->getResource()->getConnection();
		$importFileTable = $this->_entities->getTableName($this->getCode());
		$entityTable = $connection->getTableName('catalog_product_entity');

		$select = $connection->select()
			->from($entityTable, ['entity_id'])
			->join($importFileTable, "$importFileTable.sku = $entityTable.sku", []);

		return $connection->query($select)->fetchAll(\Zend_Db::FETCH_COLUMN);
	}
}