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

    public function getServiceName() : string
    {
        return $this->get('service_name');
    }

    public function setServiceName(string $serviceName)
    {
        $this->offsetSet('service_name', $serviceName);
    }

    public function isAutoStartEnabled() : bool
    {
        return $this->get('auto_start', false);
    }

    public function setIsAutoStartEnabled(bool $isAutoStartEnabled)
    {
        $this->offsetSet('auto_start', $isAutoStartEnabled);
    }

    public function isProcessCacheEnabled() : bool
    {
        return $this->get('enable_process_cache', false);
    }

    public function setIsProcessCacheEnabled(bool $isEnabled)
    {
        $this->offsetSet('enable_process_cache', $isEnabled);
    }

    /**
     * @return int
     */
    public function getStartProcesses() : int
    {
        return $this->get('start_processes', 0);
    }

    public function setStartProcesses(int $startAmount)
    {
        $this->offsetSet('start_processes', $startAmount);
    }

    public function getMaxProcesses() : int
    {
        return $this->get('max_processes', 0);
    }

    public function setMaxProcesses(int $limit)
    {
        $this->offsetSet('max_processes', $limit);
    }

    public function getMinSpareProcesses() : int
    {
        return $this->get('min_spare_processes', 0);
    }

    public function setMinSpareProcesses(int $limit)
    {
        $this->offsetSet('min_spare_processes', $limit);
    }

    public function getMaxSpareProcesses() : int
    {
        return $this->get('max_spare_processes', 0);
    }

    public function setMaxSpareProcesses(int $limit)
    {
        $this->offsetSet('max_spare_processes', $limit);
    }

    public function getProcessIdleTimeout() : int
    {
        return $this->get('processes_idle_timeout', 10);
    }

    public function setProcessIdleTimeout(int $timeInSeconds)
    {
        $this->offsetSet('processes_idle_timeout', $timeInSeconds);
    }

    public function getIpcDirectory() : string
    {
        $directory = $this->get('ipc_directory', getcwd() . '/');

        return preg_match('~\/$~', $directory) ? $directory : $directory . '/';
    }

    public function setIpcDirectory(string $directory)
    {
        $this->offsetSet('ipc_directory', $directory);
    }

    public function setMaxProcessTasks(int $limit)
    {
        $this->offsetSet('max_process_tasks', $limit);
    }

    public function getMaxProcessTasks() : int
    {
        return $this->get('max_process_tasks', 0);
    }
}