<?php

namespace Pimgento\Attribute\Helper;

use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\DataObject;

class Type extends AbstractHelper
{

    /**
     * Match Pim type with Magento attribute logic
     *
     * @param string $pimType
     * @return array
     */
    public function getType($pimType = 'default')
    {
        $types = array(
            'default'                      => 'text',
            'pim_catalog_identifier'       => 'text',
            'pim_catalog_text'             => 'text',
            'pim_catalog_metric'           => 'text',
            'pim_catalog_number'           => 'text',
            'pim_catalog_textarea'         => 'textarea',
            'pim_catalog_date'             => 'date',
            'pim_catalog_boolean'          => 'boolean',
            'pim_catalog_simpleselect'     => 'select',
            'pim_catalog_multiselect'      => 'multiselect',
            'pim_catalog_price_collection' => 'price',
            'pim_catalog_tax'              => 'tax',
        );

        $types = array_merge($types, $this->getAdditionalTypes());

        return isset($types[$pimType]) ? $this->getConfiguration($types[$pimType]) : $this->getConfiguration();
    }

    /**
     * Retrieve additional types
     *
     * @return array
     */
    public function getAdditionalTypes()
    {
        $types = $this->scopeConfig->getValue('pimgento/attribute/types');

        $additional = array();

        if ($types) {
            $types = unserialize($types);
            if (is_array($types)) {
                foreach ($types as $type) {
                    $additional[$type['pim_type']] = $type['magento_type'];
                }
            }
        }

        return $additional;
    }

    /**
     * Retrieve configuration with input type
     *
     * @param string $inputType
     * @return array
     */
    protected function getConfiguration($inputType = 'default')
    {
        $types = array(
            'default' =>  array(
                'backend_type' => 'varchar',
                'frontend_input' => 'text',
                'backend_model' => NULL,
                'source_model' => NULL,
                'frontend_model' => NULL,
            ),
            'text' =>  array(
                'backend_type' => 'varchar',
                'frontend_input' => 'text',
                'backend_model' => NULL,
                'source_model' => NULL,
                'frontend_model' => NULL,
            ),
            'textarea' => array(
                'backend_type' => 'text',
                'frontend_input' => 'textarea',
                'backend_model' => NULL,
                'source_model' => NULL,
                'frontend_model' => NULL,
            ),
            'date' => array(
                'backend_type' => 'datetime',
                'frontend_input' => 'date',
                'backend_model' => 'Magento\Eav\Model\Entity\Attribute\Backend\Datetime',
                'source_model' => NULL,
                'frontend_model' => NULL,
            ),
            'boolean' => array(
                'backend_type' => 'int',
                'frontend_input' => 'boolean',
                'backend_model' => NULL,
                'source_model' => 'Magento\Eav\Model\Entity\Attribute\Source\Boolean',
                'frontend_model' => NULL,
            ),
            'multiselect' => array(
                'backend_type' => 'varchar',
                'frontend_input' => 'multiselect',
                'backend_model' => 'Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend',
                'source_model' => NULL,
                'frontend_model' => NULL,
            ),
            'select' => array(
                'backend_type' => 'int',
                'frontend_input' => 'select',
                'backend_model' => NULL,
                'source_model' => 'Magento\Eav\Model\Entity\Attribute\Source\Table',
                'frontend_model' => NULL,
            ),
            'price' => array(
                'backend_type' => 'decimal',
                'frontend_input' => 'price',
                'backend_model' => 'Magento\Catalog\Model\Product\Attribute\Backend\Price',
                'source_model' => NULL,
                'frontend_model' => NULL,
            ),
            'tax' => array(
                'backend_type' => 'static',
                'frontend_input' => 'weee',
                'backend_model' => 'Magento\Weee\Model\Attribute\Backend\Weee\Tax',
                'source_model' => NULL,
                'frontend_model' => NULL,
            ),
        );

        $response = new DataObject();
        $response->setTypes($types);

        $this->_eventManager->dispatch(
            'pimgento_attribute_get_configuration_add_before',
            ['response' => $response]
        );

        $types = $response->getTypes();

        return isset($types[$inputType]) ? $types[$inputType] : $types['default'];
    }

    /**
     * Retrieve available Magento types
     */
    public function getAvailableTypes()
    {
        $types =  array(
            'text'        => 'text',
            'textarea'    => 'textarea',
            'date'        => 'date',
            'boolean'     => 'boolean',
            'multiselect' => 'multiselect',
            'select'      => 'select',
            'price'       => 'price',
            'tax'         => 'tax',
        );

        $response = new DataObject();
        $response->setTypes($types);

        $this->_eventManager->dispatch(
            'pimgento_attribute_get_available_types_add_after',
            ['response' => $response]
        );

        $types = $response->getTypes();

        return $types;
    }

    /**
     * Get the specific columns that depends on the attribute type
     *
     * @return array
     */
    public function getSpecificColumns()
    {
        $columns = array(
            'backend_type'   => 'VARCHAR(255) NULL',
            'frontend_input' => 'VARCHAR(255) NULL',
            'backend_model'  => 'VARCHAR(255) NULL',
            'source_model'   => 'VARCHAR(255) NULL',
            'frontend_model' => 'VARCHAR(255) NULL',
        );

        $response = new DataObject();
        $response->setColumns($columns);

        $this->_eventManager->dispatch(
            'pimgento_attribute_get_specific_columns_add_after',
            ['response' => $response]
        );

        $columns = $response->getColumns();

        return $columns;
    }
}