<?php

namespace Zeus\Kernel\Scheduler;

interface ConfigInterface extends \ArrayAccess
{
    public function __construct($fromArray = null);

    public function getMaxProcesses() : int;

    public function getStartProcesses() : int;

    public function getMinSpareProcesses() : int;

    public function getMaxSpareProcesses() : int;

    public function getProcessIdleTimeout() : int;

    public function getIpcDirectory() : string;

    public function getMaxProcessTasks() : int;

    public function getServiceName() : string;

    /**
     * @return mixed[]
     */
    public function toArray();

    public function isProcessCacheEnabled() : bool;
}