<?php

namespace Pimgento\Category\Observer;

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
        return 'category';
    }

    /**
     * Get the import name
     *
     * @return string
     */
    protected function getImportName()
    {
        return __('Categories');
    }

    /**
     * Get the default import classname
     *
     * @return string
     */
    protected function getImportDefaultClassname()
    {
        return '\Pimgento\Category\Model\Factory\Import';
    }

    /**
     * Get the sort order
     *
     * @return int
     */
    protected function getImportSortOrder()
    {
        return 10;
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
                'comment' => __('Add product required data'),
                'method'  => 'addRequiredData',
            ),
            array(
                'comment' => __('Fill temporary table'),
                'method'  => 'insertData',
            ),
            array(
                'comment' => __('Match code with Magento ID'),
                'method'  => 'matchEntity',
            ),
            array(
                'comment' => __('Create URL key'),
                'method'  => 'setUrlKey',
            ),
            array(
                'comment' => __('Create structure'),
                'method'  => 'setStructure',
            ),
            array(
                'comment' => __('Create position'),
                'method'  => 'setPosition',
            ),
            array(
                'comment' => __('Create and update category entities'),
                'method'  => 'createEntities',
            ),
            array(
                'comment' => __('Set values to attributes'),
                'method'  => 'setValues',
            ),
            array(
                'comment' => __('Count of child categories'),
                'method'  => 'updateChildrenCount',
            ),
            array(
                'comment' => __('Set Url Rewrite'),
                'method'  => 'setUrlRewrite',
            )
        );

        $stepsAfter = array(
            array(
                'comment' => __('Drop temporary table'),
                'method'  => 'dropTable',
            ),
            array(
                'comment' => __('Clean cache'),
                'method'  => 'cleanCache',
            )
        );

        return array_merge(
            $stepsBefore,
            $this->getAdditionnalSteps(),
            $stepsAfter
        );
    }
}
