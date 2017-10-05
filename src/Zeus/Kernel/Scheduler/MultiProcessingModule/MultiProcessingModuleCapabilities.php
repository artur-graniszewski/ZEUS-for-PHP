<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

class MultiProcessingModuleCapabilities
{
    const ISOLATION_PROCESS = 1;

    const ISOLATION_THREAD = 2;

    const ISOLATION_NONE = 4;

    /** @var int */
    protected $isolationLevel =  self::ISOLATION_NONE;

    /**
     * @return int
     */
    public function getIsolationLevel()
    {
        return $this->isolationLevel;
    }

    /**
     * @param int $isolationLevel
     * @return $this
     */
    public function setIsolationLevel($isolationLevel)
    {
        $this->isolationLevel = $isolationLevel;

        return $this;
    }


}