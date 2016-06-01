<?php

namespace Pimgento\Entities\Model\Source;

class InsertionMethod implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Retrieve Insertion method Option array
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => \Pimgento\Entities\Helper\Config::INSERTION_METHOD_DATA_IN_FILE, 'label' => __('Data in file')],
            ['value' => \Pimgento\Entities\Helper\Config::INSERTION_METHOD_BY_ROWS, 'label' => __('By 1000 rows')]
        ];
    }
}