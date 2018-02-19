<?php

namespace ZeusTest\Helpers;

use Zeus\Kernel\Scheduler\MultiProcessingModule\ProcessOpen\ProcessOpenBridge;

class ProcessOpenBridgeMock extends ProcessOpenBridge
{
    private $isSupported = true;

    /**
     * @internal
     */
    public function isSupported() : bool
    {
        return $this->isSupported;
    }

    /**
     * @param bool $isSupported
     */
    public function setIsSupported(bool $isSupported)
    {
        $this->isSupported = $isSupported;
    }
}