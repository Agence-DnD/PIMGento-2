<?php


namespace Pimgento\Staging\Model\Source;

/**
 * Class To display different stating modes in the BO.
 *
 * @author    de Cramer Oliver<oldec@smile.fr>
 * @copyright 2017 Smile
 * @package Pimgento\Staging\Model\Source
 */
class StagingMode implements \Magento\Framework\Option\ArrayInterface
{

    /**
     * @var \Pimgento\Staging\Helper\Config
     */
    protected $configHelper;

    /**
     * PHP Constructor to get the module manager to be able to display proper options.
     *
     * @param \Pimgento\Staging\Helper\Config $configHelper
     */
    public function __construct(
        \Pimgento\Staging\Helper\Config $configHelper
    ) {
        $this->configHelper = $configHelper;
    }


    /**
     * Retrieve Insertion method Option array
     *
     * @return array
     */
    public function toOptionArray()
    {
        // Allow staging mode only if the catalog staging modules are enabled.
        if ($this->configHelper->isCatalogStagingModulesEnabled()) {
            $options = [
                [
                    'value' => \Pimgento\Staging\Helper\Config::STAGING_MODE_LAST,
                    'label' => __('Update Last Created Stage')
                ],
                [
                    'value' => \Pimgento\Staging\Helper\Config::STAGING_MODE_CURRENT,
                    'label' => __('Update Current Stage')
                ],
                [
                    'value' => \Pimgento\Staging\Helper\Config::STAGING_MODE_ALL,
                    'label' => __('Update All Stages')
                ],
                [
                    'value' => \Pimgento\Staging\Helper\Config::STAGING_MODE_FULL,
                    'label' => __('Full - Require "to" and "from" columns)')
                ]
            ];
        } else {
            $options = [
                [
                    'value' => \Pimgento\Staging\Helper\Config::STAGING_MODE_DISABLED,
                    'label' => __("Disabled - Staging isn't activeted")
                ],
            ];
        }

        return $options;
    }
}