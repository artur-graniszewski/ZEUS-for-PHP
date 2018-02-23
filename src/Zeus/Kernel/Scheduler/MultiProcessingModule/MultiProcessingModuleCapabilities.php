<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

class MultiProcessingModuleCapabilities
{
    const ISOLATION_PROCESS = 1;
    const ISOLATION_THREAD = 2;
    const ISOLATION_NONE = 4;

    private $isolationName = [
        self::ISOLATION_PROCESS => 'process',
        self::ISOLATION_THREAD => 'thread',
        self::ISOLATION_NONE => 'none',
    ];

    /** @var bool */
    private $isSignalAsyncMode = false;

    /** @var int */
    private $isolationLevel =  self::ISOLATION_NONE;

    /** @var bool */
    private $isCopyingParentMemoryPages = false;

    public function getIsolationLevel() : int
    {
        return $this->isolationLevel;
    }

    public function setIsolationLevel(int $isolationLevel)
    {
        $this->isolationLevel = $isolationLevel;
    }

    public function getIsolationLevelName() : string
    {
        return $this->isolationName[$this->isolationLevel];
    }

    public function setAsyncSignalHandler(bool $isAsync)
    {
        $this->isSignalAsyncMode = $isAsync;
    }

    public function setSharedInitialAddressSpace(bool $isShared)
    {
        $this->isCopyingParentMemoryPages = $isShared;
    }

    public function isCopyingParentMemoryPages() : bool
    {
        return $this->isCopyingParentMemoryPages;
    }

    public function isAsyncSignalHandler() : bool
    {
        return $this->isSignalAsyncMode;
    }
}