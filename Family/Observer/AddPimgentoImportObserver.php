<?php

namespace Pimgento\Family\Observer;

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
        return 'family';
    }

    /**
     * Get the import name
     *
     * @return string
     */
    protected function getImportName()
    {
        return __('Families');
    }

    /**
     * Get the default import classname
     *
     * @return string
     */
    protected function getImportDefaultClassname()
    {
        return '\Pimgento\Family\Model\Factory\Import';
    }

    /**
     * Get the sort order
     *
     * @return int
     */
    protected function getImportSortOrder()
    {
        return 20;
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
                'comment' => __('Match code with Magento ID'),
                'method'  => 'matchEntity',
            ),
            array(
                'comment' => __('Create or update families'),
                'method'  => 'insertFamily',
            ),
            array(
                'comment' => __('Create or update family attribute relations'),
                'method'  => 'insertFamilyAttributeRelations'
            ),
            array(
                'comment' => __('Init families from default skeleton'),
                'method'  => 'initGroup',
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
