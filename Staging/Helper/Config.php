<?php


namespace Pimgento\Staging\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;

/**
 * Class Config
 *
 * @author    de Cramer Oliver<oldec@smile.fr>
 * @copyright 2017 Smile
 * @package Pimgento\Staging\Helper
 */
class Config extends AbstractHelper
{
    /**
     * Constants to configuration paths.
     */
    const CONFIG_PATH_STAGING_MODE = 'pimgento/%s/staging_mode';

    /**
     * Constants for different staging modes.
     */
    const STAGING_MODE_DISABLED = 'disabled';
    const STAGING_MODE_LAST = 'last';
    const STAGING_MODE_CURRENT = 'current';
    const STAGING_MODE_ALL = 'all';
    const STAGING_MODE_FULL = 'full';

    /**
     * @var \Magento\Framework\Module\Manager
     */
    protected $moduleManager;

    /**
     * Constructor
     *
     * @param Context $context
     * @param \Magento\Framework\Module\Manager $moduleManager
     */
    public function __construct(
        Context $context,
        \Magento\Framework\Module\Manager $moduleManager
    ) {
        $this->moduleManager = $moduleManager;

        parent::__construct($context);
    }

    /**
     * Check if staging module is enabled for the catalog.
     *
     * @return bool
     */
    public function isCatalogStagingModulesEnabled()
    {
        return $this->moduleManager->isEnabled('Magento_Staging')
            && $this->moduleManager->isEnabled('Magento_CatalogStaging');
    }

    /**
     * Get import staging mode to use.
     *
     * @return mixed|string
     */
    public function getImportStagingMode($profile)
    {

        if (!$this->isCatalogStagingModulesEnabled()) {
            return self::STAGING_MODE_DISABLED;
        } else {
            return $this->scopeConfig->getValue(sprintf(self::CONFIG_PATH_STAGING_MODE, $profile));
        }
    }
}