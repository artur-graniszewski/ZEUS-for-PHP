<?php

namespace Zeus\Kernel\ProcessManager;

interface ConfigInterface extends \ArrayAccess
{
    public function __construct($fromArray = null);

    /**
     * @return int
     */
    public function getMaxProcesses();

    /**
     * @return int
     */
    public function getStartProcesses();

    /**
     * @return int
     */
    public function getMinSpareProcesses();

    /**
     * @return int
     */
    public function getMaxSpareProcesses();

    /**
     * @return int
     */
    public function getProcessIdleTimeout();

    /**
     * @return string
     */
    public function getIpcDirectory();

    /**
     * @return int
     */
    public function getMaxProcessTasks();

    /**
     * @return string
     */
    public function getServiceName();

    /**
     * @return mixed[]
     */
    public function toArray();

    /**
     * @return bool
     */
    public function isProcessCacheEnabled();
}