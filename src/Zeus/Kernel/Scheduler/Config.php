<?php

namespace Zeus\Kernel\Scheduler;

/**
 * Server configuration class.
 */
class Config extends \Zend\Config\Config implements ConfigInterface
{
    /**
     * Config constructor.
     * @param mixed[]|ConfigInterface $fromArray
     */
    public function __construct($fromArray = null)
    {
        if ($fromArray instanceof ConfigInterface) {
            $fromArray = $fromArray->toArray();
        }

        parent::__construct($fromArray, true);
    }

    /**
     * @return string
     */
    public function getServiceName()
    {
        return $this->get('service_name');
    }

    /**
     * @param string $serviceName
     * @return $this
     */
    public function setServiceName($serviceName)
    {
        $this->offsetSet('service_name', $serviceName);

        return $this;
    }

    /**
     * @return bool
     */
    public function isAutoStartEnabled()
    {
        return $this->get('auto_start');
    }

    /**
     * @param bool $isAutoStartEnabled
     * @return $this
     */
    public function setIsAutoStartEnabled($isAutoStartEnabled)
    {
        $this->offsetSet('auto_start', $isAutoStartEnabled);

        return $this;
    }

    /**
     * @return bool
     */
    public function isProcessCacheEnabled()
    {
        return $this->get('enable_process_cache', false);
    }

    /**
     * @param bool $isEnabled
     * @return $this
     */
    public function setIsProcessCacheEnabled($isEnabled)
    {
        $this->offsetSet('enable_process_cache', $isEnabled);

        return $this;
    }

    /**
     * @return int
     */
    public function getStartProcesses()
    {
        return $this->get('start_processes');
    }

    /**
     * @param int $startAmount
     * @return $this
     */
    public function setStartProcesses($startAmount)
    {
        $this->offsetSet('start_processes', $startAmount);

        return $this;
    }

    /**
     * @return int
     */
    public function getMaxProcesses()
    {
        return $this->get('max_processes');
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setMaxProcesses($limit)
    {
        $this->offsetSet('max_processes', $limit);

        return $this;
    }

    /**
     * @return int
     */
    public function getMinSpareProcesses()
    {
        return $this->get('min_spare_processes');
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setMinSpareProcesses($limit)
    {
        $this->offsetSet('min_spare_processes', $limit);

        return $this;
    }

    /**
     * @return int
     */
    public function getMaxSpareProcesses()
    {
        return $this->get('max_spare_processes');
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setMaxSpareProcesses($limit)
    {
        $this->offsetSet('max_spare_processes', $limit);

        return $this;
    }

    /**
     * @return int
     */
    public function getProcessIdleTimeout()
    {
        return $this->get('processes_idle_timeout', 10);
    }

    /**
     * @param int $timeInSeconds
     * @return $this
     */
    public function setProcessIdleTimeout($timeInSeconds)
    {
        $this->offsetSet('processes_idle_timeout', $timeInSeconds);

        return $this;
    }

    /**
     * @return string
     */
    public function getIpcDirectory()
    {
        $directory = $this->get('ipc_directory', getcwd() . '/');

        return preg_match('~\/$~', $directory) ? $directory : $directory . '/';
    }

    /**
     * @param string $directory
     * @return $this
     */
    public function setIpcDirectory($directory)
    {
        $this->offsetSet('ipc_directory', $directory);

        return $this;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setMaxProcessTasks($limit)
    {
        $this->offsetSet('max_process_tasks', $limit);

        return $this;
    }

    /**
     * @return int
     */
    public function getMaxProcessTasks()
    {
        return $this->get('max_process_tasks');
    }
}