<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

class MultiProcessingModuleCapabilities
{
    const ISOLATION_PROCESS = 1;

    const ISOLATION_THREAD = 2;

    const ISOLATION_NONE = 4;

    /** @var int */
    private $isolationLevel =  self::ISOLATION_NONE;

    public function getIsolationLevel() : int
    {
        return $this->isolationLevel;
    }

    public function setIsolationLevel(int $isolationLevel)
    {
        $this->isolationLevel = $isolationLevel;
    }
}