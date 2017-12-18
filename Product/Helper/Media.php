<?php

namespace Pimgento\Product\Helper;

use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;
use \Magento\Framework\App\Filesystem\DirectoryList;
use \Magento\Eav\Model\AttributeRepository;

class Media extends AbstractHelper
{
    /**
     * @var DirectoryList
     */
    protected $directoryList;

    /**
     * @var AttributeRepository
     */
    protected $attributeRepository;

    /**
     * @var array
     */
    protected $imageConfig = [];

    /**
     * @var string
     */
    protected $mediaPath = '';

    /**
     * PHP Constructor
     *
     * @param Context $context
     * @param DirectoryList $directoryList
     * @param AttributeRepository $attributeRepository
     */
    public function __construct(
        Context $context,
        DirectoryList $directoryList,
        AttributeRepository $attributeRepository
    )
    {

        parent::__construct($context);

        $this->directoryList = $directoryList;
        $this->attributeRepository = $attributeRepository;
    }

    /**
     * init configuration
     *
     * @param string $currentImportFolder
     *
     * @return void
     */
    public function initHelper($currentImportFolder)
    {
        $this->mediaPath = $this->directoryList->getPath('media') . '/catalog/product/';

        $this->imageConfig = $this->getImageConfig();

        // clean up empty fields
        foreach ($this->imageConfig['fields'] as $field => $values) {
            if (count($values) == 0) {
                unset($this->imageConfig['fields'][$field]);
            }
        }

        // build import folder
        $importFolder = $currentImportFolder . '/';
        $value = trim($this->scopeConfig->getValue('pimgento/image/path'));
        if ($value) {
            $importFolder .= $value . '/';
        }
        $this->imageConfig['import_folder'] = str_replace('//', '/', $importFolder);

        $this->imageConfig['media_gallery_attribute_id'] = (int)$this->getAttributeIdByCode('media_gallery');
    }

    /**
     * get image configuration settings
     *
     * @return array
     */
    public function getImageConfig()
    {
        $imageConfig = [
            'fields' => [
                'base_image' => [
                    'columns' => $this->getFieldDefinition('pimgento/image/base_image', false),
                    'attribute_id' => $this->getAttributeIdByCode('image'),
                ],
                'small_image' => [
                    'columns' => $this->getFieldDefinition('pimgento/image/small_image', false),
                    'attribute_id' => $this->getAttributeIdByCode('small_image'),
                ],
                'thumbnail_image' => [
                    'columns' => $this->getFieldDefinition('pimgento/image/thumbnail_image', false),
                    'attribute_id' => $this->getAttributeIdByCode('thumbnail'),
                ],
                'swatch_image' => [
                    'columns' => $this->getFieldDefinition('pimgento/image/swatch_image', false),
                    'attribute_id' => $this->getAttributeIdByCode('swatch_image'),
                ],
                'gallery' => [
                    'columns' => $this->getFieldDefinition('pimgento/image/gallery_image', true),
                    'attribute_id' => null,
                ],
            ],
            'clean_files' => (((int)$this->scopeConfig->getValue('pimgento/image/clean_files')) == 1),
        ];

        return $imageConfig;

    }

    /**
     * Get the field definition from the config
     *
     * @param string $path
     * @param boolean $multipleValues
     *
     * @return string[]
     */
    protected function getFieldDefinition($path, $multipleValues)
    {
        $values = trim($this->scopeConfig->getValue($path));

        $values = $multipleValues ? explode(',', $values) : [$values];

        foreach ($values as $key => $value) {
            $value = trim($value);
            $values[$key] = $value;
            if ($value == '') {
                unset($values[$key]);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * get attribute id by code
     *
     * @param string $code
     *
     * @return int
     */
    protected function getAttributeIdByCode($code)
    {
        return (int)$this->attributeRepository
            ->get('catalog_product', $code)
            ->getAttributeId();
    }

    /**
     * Get the list of all the fields
     *
     * @return array
     */
    public function getFields()
    {
        return $this->imageConfig['fields'];
    }

    /**
     * get the import absolute path
     *
     * @return string
     */
    public function getImportFolder()
    {
        return $this->imageConfig['import_folder'];
    }

    /**
     * get the media path
     *
     * @return string
     */
    public function getMediaAbsolutePath()
    {
        return $this->mediaPath;
    }

    /**
     * Do we have to clean the import folder for medias ?
     *
     * @return bool
     */
    public function isCleanFiles()
    {
        return isset($this->imageConfig['clean_files']) ? $this->imageConfig['clean_files'] : 0;
    }

    /**
     * Get the id of the attribute "media gallery"
     *
     * @return int
     */
    public function getMediaGalleryAttributeId()
    {
        return $this->imageConfig['media_gallery_attribute_id'];
    }

    /**
     * Clean the file import folder
     *
     * @return void
     */
    public function cleanFiles()
    {
        $folder = $this->getImportFolder() . 'files/';

        if (is_dir($folder)) {
            $this->delTree($folder);
        }
    }

    /**
     * recursive remove dir
     *
     * @param string $dir
     *
     * @return boolean
     */
    protected function delTree($dir)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->delTree("$dir/$file") : unlink("$dir/$file");
        }

        return rmdir($dir);
    }
}