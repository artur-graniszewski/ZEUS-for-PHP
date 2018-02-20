<?php

namespace ZeusTest\Helpers;

use Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads\PosixThreadBridgeInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads\ThreadWrapperInterface;

/**
 * @internal
 */
class PosixThreadBridgeMock implements PosixThreadBridgeInterface
{
    private $isSupported = true;

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