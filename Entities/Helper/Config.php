<?php

namespace Pimgento\Entities\Helper;

use \Magento\Framework\App\Helper\AbstractHelper;

class Config extends AbstractHelper
{
    /**
     * Data in file insertion method
     */
    const INSERTION_METHOD_DATA_IN_FILE = 'data_in_file';

    /**
     * By rows insertion method
     */
    const INSERTION_METHOD_BY_ROWS = 'by_rows';

    /**
     * Retrieve CSV configuration
     *
     * @return array
     */
    public function getCsvConfig()
    {
        return array(
            'lines_terminated'  => $this->scopeConfig->getValue('pimgento/general/lines_terminated'),
            'fields_terminated' => $this->scopeConfig->getValue('pimgento/general/fields_terminated'),
            'fields_enclosure'  => $this->scopeConfig->getValue('pimgento/general/fields_enclosure'),
        );
    }

    /**
     * Retrieve Load Data Infile option
     *
     * @return int
     */
    public function getLoadDataLocal()
    {
        return $this->scopeConfig->getValue('pimgento/general/load_data_local');
    }

    /**
     * Retrieve insertion method
     *
     * @return string
     */
    public function getInsertionMethod()
    {
        return (string) $this->scopeConfig->getValue('pimgento/general/data_insertion_method');
    }

}