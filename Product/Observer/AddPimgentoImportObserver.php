<?php

namespace Pimgento\Product\Observer;

use Magento\Framework\Event\ObserverInterface;
use Pimgento\Import\Observer\AbstractAddImportObserver;

class AddPimgentoImportObserver extends AbstractAddImportObserver implements ObserverInterface
{
    /**
     * Get the import code
     *
     * @return string
     */
    protected function getImportCode()
    {
        return 'product';
    }

    /**
     * Get the import name
     *
     * @return string
     */
    protected function getImportName()
    {
        return __('Products');
    }

    /**
     * Get the default import classname
     *
     * @return string
     */
    protected function getImportDefaultClassname()
    {
        return '\Pimgento\Product\Model\Factory\Import';
    }

    /**
     * Get the sort order
     *
     * @return int
     */
    protected function getImportSortOrder()
    {
        return 60;
    }

    /**
     * get the steps definition
     *
     * @return array
     */
    protected function getStepsDefinition()
    {
        $stepsBefore = array(
            array(
                'comment' => __('Create temporary table'),
                'method'  => 'createTable',
            ),
            array(
                'comment' => __('Fill temporary table'),
                'method'  => 'insertData',
            ),
            array(
                'comment' => __('Add product required data'),
                'method'  => 'addRequiredData',
            ),
            array(
                'comment' => __('Match code with Magento ID'),
                'method'  => 'checkStageDates',
            ),
            array(
                'comment' => __('Create configurable product'),
                'method'  => 'createConfigurable',
            ),
            array(
                'comment' => __('Match code with Magento ID'),
                'method'  => 'matchEntity',
            ),
            array(
                'comment' => __('Match family code with Magento id'),
                'method'  => 'updateAttributeSetId',
            ),
            array(
                'comment' => __('Update column values for options'),
                'method'  => 'updateOption',
            ),
            array(
                'comment' => __('Create or update product entities'),
                'method'  => 'createEntities',
            ),
        );

        $afterEntitiesCreationSteps = array(
            array(
                'comment' => __('Set values to attributes'),
                'method'  => 'setValues',
            ),
            array(
                'comment' => __('Link configurable with children'),
                'method'  => 'linkConfigurable',
            ),
            array(
                'comment' => __('Set products to websites'),
                'method'  => 'setWebsites',
            ),
            array(
                'comment' => __('Set products to categories'),
                'method'  => 'setCategories',
            ),
            array(
                'comment' => __('Init stock'),
                'method'  => 'initStock',
            ),
            array(
                'comment' => __('Update related, up-sell and cross-sell products'),
                'method'  => 'setRelated'
            ),
            array(
                'comment' => __('Set Url Rewrite'),
                'method'  => 'setUrlRewrite',
            ),
            array(
                'comment' => __('Import media files'),
                'method'  => 'importMedia',
            ),
            /* Step that will work only if staging enabled & certain options are selected. */
            array(
                'comment' => __('Update stages'),
                'method'  => 'updateAllStages',
            ),
        );

        $stepsAfter = array(
            array(
                'comment' => __('Drop temporary table'),
                'method'  => 'dropTable',
            ),
            array(
                'comment' => __('Clean Media Folder'),
                'method'  => 'cleanMediaFolder',
            ),
            array(
                'comment' => __('Clean cache'),
                'method'  => 'cleanCache',
            )
        );

        return array_merge(
            $stepsBefore,
            $this->getAdditionnalSteps('update_entities_add_steps', 'update_entities_steps'),
            $afterEntitiesCreationSteps,
            $this->getAdditionnalSteps(),
            $stepsAfter
        );
    }
}