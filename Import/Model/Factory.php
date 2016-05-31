<?php

namespace Pimgento\Import\Model;

use \Magento\Framework\DataObject;
use \Pimgento\Import\Api\Data\FactoryInterface;
use \Pimgento\Import\Helper\Config as helperConfig;
use \Magento\Framework\Event\ManagerInterface;
use \Magento\Framework\Module\Manager as moduleManager;
use \Magento\Framework\App\Config\ScopeConfigInterface as scopeConfig;
use \Exception;

class Factory extends DataObject implements FactoryInterface
{

    /**
     * @var array
     */
    protected $_steps = null;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $_eventManager;

    /**
     * @var \Pimgento\Import\Helper\Config
     */
    protected $_helperConfig;

    /**
     * @var \Magento\Framework\Module\Manager
     */
    protected $_moduleManager;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @param \Pimgento\Import\Helper\Config $helperConfig
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Framework\Module\Manager
     * @param \Magento\Framework\App\Config\ScopeConfigInterface
     * @param array $data
     */
    public function __construct(
        helperConfig $helperConfig,
        ManagerInterface $eventManager,
        moduleManager $moduleManager,
        scopeConfig $scopeConfig,
        array $data = []
    )
    {
        parent::__construct($data);
        $this->_eventManager = $eventManager;
        $this->_helperConfig = $helperConfig;
        $this->_moduleManager = $moduleManager;
        $this->_scopeConfig = $scopeConfig;
    }

    /**
     * Execute method
     *
     * @return $this
     */
    public function execute()
    {
        $this->_eventManager->dispatch('pimgento_import_step_start', ['import' => $this]);
        $this->_eventManager->dispatch(
            'pimgento_import_step_start_' . strtolower($this->getCode()), ['import' => $this]
        );

        if ($this->canExecute()) {
            $this->initStatus();
            try {
                $method = $this->getMethod();
                $this->$method();
            } catch (Exception $e) {
                $this->unsetStatus();
                $this->setMessage($e->getMessage());
            }
        }

        $this->_eventManager->dispatch('pimgento_import_step_finish', ['import' => $this]);
        $this->_eventManager->dispatch(
            'pimgento_import_step_finish_' . strtolower($this->getCode()), ['import' => $this]
        );

        return $this;
    }

    /**
     * Check method can be executed
     *
     * @return bool
     */
    public function canExecute()
    {
        $canExecute = false;

        if (is_int($this->getStep()) && $this->getStep() < $this->countSteps()) {
            if ($this->getMethod()) {
                if (method_exists($this, $this->getMethod())) {
                    $canExecute = true;
                }
            }
        }

        if ($this->getFileIsRequired() && !$this->getFile()) {
            $canExecute = false;
        }

        return $canExecute;
    }

    /**
     * Retrieve steps count
     *
     * @return int
     */
    public function countSteps()
    {
        return count($this->getSteps());
    }

    /**
     * Next step
     *
     * @return $this
     */
    public function next()
    {
        $this->unsetStatus();

        $step = $this->getStep();
        if (!is_null($step)) {
            $this->setStep($step + 1);
        }

        return $this;
    }

    /**
     * Retrieve upload directory
     *
     * @return string
     */
    public function getUploadDir()
    {
        return $this->_helperConfig->getUploadDir();
    }

    /**
     * Retrieve identifier
     *
     * @return string
     */
    public function getIdentifier()
    {
        if (is_null($this->getData(self::IDENTIFIER))) {
            $this->setIdentifier(uniqid());
        }

        return $this->getData(self::IDENTIFIER);
    }

    /**
     * Set identifier
     *
     * @param string $identifier
     * @return $this
     */
    public function setIdentifier($identifier)
    {
        return $this->setData(self::IDENTIFIER, $identifier);
    }

    /**
     * Retrieve code
     *
     * @return string
     */
    public function getCode()
    {
        return $this->getData(self::CODE);
    }

    /**
     * Set Code
     *
     * @param string $code
     * @return $this
     */
    public function setCode($code)
    {
        return $this->setData(self::CODE, $code);
    }

    /**
     * Retrieve sort order
     *
     * @return int
     */
    public function getSortOrder()
    {
        return $this->getData(self::SORT_ORDER);
    }

    /**
     * Set sort order
     *
     * @param int $sort
     * @return $this
     */
    public function setSortOrder($sort)
    {
        return $this->setData(self::SORT_ORDER, $sort);
    }

    /**
     * Retrieve name
     *
     * @return string
     */
    public function getName()
    {
        return $this->getData(self::NAME);
    }

    /**
     * Set Name
     *
     * @param string $name
     * @return $this
     */
    public function setName($name)
    {
        return $this->setData(self::NAME, $name);
    }

    /**
     * Retrieve factory class
     *
     * @return string
     */
    public function getClass()
    {
        return $this->getData(self::CLASS_NAME);
    }

    /**
     * Set Class
     *
     * @param string $class
     * @return $this
     */
    public function setClass($class)
    {
        return $this->setData(self::CLASS_NAME, $class);
    }

    /**
     * Retrieve steps
     *
     * @return array
     */
    public function getSteps()
    {
        if (is_null($this->_steps)) {
            $steps = is_array($this->getData(self::STEPS)) ? $this->getData(self::STEPS) : array();

            array_unshift($steps,
                array(
                    'comment' => __('Start import'),
                    'method' => 'start'
                )
            );

            array_push($steps,
                array(
                    'comment' => __('Import complete'),
                    'method' => 'finish'
                )
            );

            $this->_steps = $steps;
        }

        return $this->_steps;
    }

    /**
     * Set Steps
     *
     * @param array $steps
     * @return $this
     */
    public function setSteps($steps)
    {
        return $this->setData(self::STEPS, $steps);
    }

    /**
     * Retrieve step number
     *
     * @return int
     */
    public function getStep()
    {
        return $this->getData(self::STEP);
    }

    /**
     * Set step number
     *
     * @param int $step
     * @return $this
     */
    public function setStep($step)
    {
        $this->unsetStatus();

        return $this->setData(self::STEP, $step);
    }

    /**
     * Retrieve message
     *
     * @return string
     */
    public function getMessage()
    {
        return $this->getPrefix() . $this->getData(self::MESSAGE);
    }

    /**
     * Set Message
     *
     * @param string $message
     * @return $this
     */
    public function setMessage($message)
    {
        return $this->setData(self::MESSAGE, $message);
    }

    /**
     * Retrieve status
     *
     * @return bool
     */
    public function getStatus()
    {
        return $this->getData(self::STATUS);
    }

    /**
     * Set status
     *
     * @param bool $status
     * @return $this
     */
    public function setStatus($status)
    {
        return $this->setData(self::STATUS, $status);
    }

    /**
     * Can continue
     *
     * @return bool
     */
    public function getContinue()
    {
        return $this->getData(self::CONTINUE_STEP);
    }

    /**
     * Set continue
     *
     * @param bool $continue
     * @return $this
     */
    public function setContinue($continue)
    {
        return $this->setData(self::CONTINUE_STEP, $continue);
    }

    /**
     * Retrieve file
     *
     * @return int
     */
    public function getFile()
    {
        return $this->getData(self::FILE);
    }

    /**
     * Set file
     *
     * @param string $file
     * @return $this
     */
    public function setFile($file)
    {
        return $this->setData(self::FILE, $file);
    }

    /**
     * Retrieve file is Required
     *
     * @return bool
     */
    public function getFileIsRequired()
    {
        return $this->getData(self::FILE_IS_REQUIRED);
    }

    /**
     * Set file is required
     *
     * @param bool $isRequired
     * @return $this
     */
    public function setFileIsRequired($isRequired)
    {
        return $this->setData(self::FILE_IS_REQUIRED, $isRequired);
    }

    /**
     * Retrieve current comment
     *
     * @return string
     */
    public function getComment()
    {
        return isset($this->getSteps()[$this->getStep()]['comment']) ?
            $this->getPrefix() . $this->getSteps()[$this->getStep()]['comment'] :
            $this->getPrefix() . get_class($this) . '::' . $this->getMethod();
    }

    /**
     * Retrieve current method
     *
     * @return string|null
     */
    public function getMethod()
    {
        return isset($this->getSteps()[$this->getStep()]['method']) ?
            $this->getSteps()[$this->getStep()]['method'] : null;
    }

    /**
     * Retrieve message or comment prefix
     *
     * @return string
     */
    public function getPrefix()
    {
        return '[' . date('H:i:s') . '] '; // @TODO GMT date
    }

    /**
     * First method
     *
     * @return bool
     */
    public function start()
    {
        $this->setMessage(
            __('Import id: %1', $this->getIdentifier())
        );
    }

    /**
     * Last method
     *
     * @return bool
     */
    public function finish()
    {
        $this->setMessage(
            __('Import id: %1', $this->getIdentifier())
        );
        $this->setContinue(false);
    }

    /**
     * Init status
     *
     * @return $this
     */
    protected function initStatus()
    {
        $this->setStatus(true);
        $this->setContinue(true);
        $this->setMessage(__('completed'));

        return $this;
    }

    /**
     * Unset step status
     *
     * @return $this
     */
    protected function unsetStatus()
    {
        $this->setStatus(false);
        $this->setContinue(false);
        $this->setMessage(null);

        return $this;
    }

    /**
     * Check if module is enabled
     *
     * @param string $module
     * @return bool
     */
    protected function moduleIsEnabled($module)
    {
        return $this->_moduleManager->isEnabled($module);
    }

    /**
     * Check if file is external
     *
     * @return bool
     */
    protected function isExternalFile()
    {
        return substr($this->getFile(), 0, 1) == '/';
    }

    /**
     * Return file full path handling potential external files
     *
     * @return string
     */
    protected function getFileFullPath()
    {
        return $this->isExternalFile() ? $this->getFile() : $this->getUploadDir() . '/' . $this->getFile();
    }

    /**
     * Return file not found error message handling potential external files
     *
     * @return string
     */
    protected function getFileNotFoundErrorMessage()
    {
        return $this->isExternalFile() ?
            __('File %1 not found', $this->getFile()) :
            __('File %1 not found in %2', $this->getFile(), $this->getUploadDir());
    }
}