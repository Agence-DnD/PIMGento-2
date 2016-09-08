<?php

namespace Pimgento\Import\Helper;

use \Magento\Framework\App\Filesystem\DirectoryList;
use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;
use \Magento\Store\Model\StoreManagerInterface;
use \Magento\Framework\Filesystem;
use \Magento\Store\Model\ScopeInterface;
use \Magento\CatalogInventory\Model\Configuration as CatalogInventoryConfiguration;

class Config extends AbstractHelper
{

    /**
     * @var \Magento\Framework\Filesystem
     */
    protected $fileSystem;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var CatalogInventoryConfiguration
     */
    protected $catalogInventoryConfiguration;

    /**
     * Constructor
     *
     * 0
     * @param Context $context
     * @param Filesystem $fileSystem
     * @param StoreManagerInterface $storeManager
     * @param CatalogInventoryConfiguration $catalogInventoryConfiguration
     */
    public function __construct(
        Context $context,
        Filesystem $fileSystem,
        StoreManagerInterface $storeManager,
        CatalogInventoryConfiguration $catalogInventoryConfiguration
    ) {
        $this->fileSystem = $fileSystem;
        $this->storeManager = $storeManager;
        $this->catalogInventoryConfiguration = $catalogInventoryConfiguration;

        parent::__construct($context);
    }

    /**
     * Retrieve upload directory
     *
     * @return string
     */
    public function getUploadDir()
    {
        /** @var $varDirectory \Magento\Framework\Filesystem\Directory\Read $mediaDirectory */
        $varDirectory = $this->fileSystem->getDirectoryRead(DirectoryList::VAR_DIR);

        return $varDirectory->getAbsolutePath(
            $this->scopeConfig->getValue('pimgento/general/import_directory')
        );
    }

    /**
     * Retrieve all stores information
     *
     * @param string|array $arrayKey
     * @return array
     */
    public function getStores($arrayKey = 'store_id')
    {
        $stores = $this->storeManager->getStores(true);

        $data = array();

        if (!is_array($arrayKey)) {
            $arrayKey = array($arrayKey);
        }

        $channels = $this->scopeConfig->getValue('pimgento/general/website_mapping');

        if ($channels) {
            $channels = unserialize($channels);
            if (!is_array($channels)) {
                $channels = array();
            }
        } else {
            $channels = array();
        }

        foreach ($stores as $store) {
            $website = $this->storeManager->getWebsite($store->getWebsiteId());

            $channel = $website->getCode();

            foreach ($channels as $match) {
                if ($match['website'] == $website->getCode()) {
                    $channel = $match['channel'];
                }
            }

            $combine = array();

            foreach ($arrayKey as $key) {
                switch ($key) {
                    case 'store_id':
                        $combine[] = $store->getId();
                        break;
                    case 'store_code':
                        $combine[] = $store->getCode();
                        break;
                    case 'website_id':
                        $combine[] = $website->getId();
                        break;
                    case 'website_code':
                        $combine[] = $website->getCode();
                        break;
                    case 'channel_code':
                        $combine[] = $channel;
                        break;
                    case 'lang':
                        $combine[] = $this->scopeConfig->getValue(
                            'general/locale/code',
                            ScopeInterface::SCOPE_STORE,
                            $store->getId()
                        );
                        break;
                    case 'currency':
                        $combine[] = $this->scopeConfig->getValue(
                            'currency/options/default',
                            ScopeInterface::SCOPE_STORE,
                            $store->getId()
                        );
                        break;
                    default:
                        $combine[] = $store->getId();
                        break;
                }
            }

            $key = join('-', $combine);

            if (!isset($data[$key])) {
                $data[$key] = array();
            }

            $data[$key][] = array(
                'store_id'     => $store->getId(),
                'store_code'   => $store->getCode(),
                'website_id'   => $website->getId(),
                'website_code' => $website->getCode(),
                'channel_code' => $channel,
                'lang'         => $this->scopeConfig->getValue(
                    'general/locale/code',
                    ScopeInterface::SCOPE_STORE,
                    $store->getId()
                ),
                'currency'     => $this->scopeConfig->getValue(
                    'currency/options/default',
                    ScopeInterface::SCOPE_STORE,
                    $store->getId()
                ),
            );
        }

        return $data;
    }

    /**
     * Retrieve default website id
     *
     * @return int
     */
    public function getDefaultWebsiteId()
    {
        return $this->storeManager->getStore()->getWebsiteId();
    }

    /**
     * Retrieve default scope id used by the catalog inventory module when saving an entity
     *
     * @return int
     */
    public function getDefaultScopeId()
    {
        return $this->catalogInventoryConfiguration->getDefaultScopeId();
    }
}
