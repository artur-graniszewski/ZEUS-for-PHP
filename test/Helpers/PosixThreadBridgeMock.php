<?php

namespace ZeusTest\Helpers;

use Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads\PosixThreadBridgeInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads\ThreadWrapperInterface;

class PosixThreadBridgeMock implements PosixThreadBridgeInterface
{
    private $isSupported = true;

    /**
     * @internal
     */
    public function isSupported() : bool
    {
        return $this->isSupported;
    }

    public function setIsSupported(bool $isSupported)
    {
        $this->isSupported = $isSupported;
    }

    public function getNewThread(): ThreadWrapperInterface
    {
        return new PosixThreadWrapperMock();
    }
}