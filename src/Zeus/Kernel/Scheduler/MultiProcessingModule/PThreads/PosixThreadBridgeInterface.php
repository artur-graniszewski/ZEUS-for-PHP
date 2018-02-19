<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads;

interface PosixThreadBridgeInterface
{
    public function isSupported() : bool;

    public function getNewThread() : ThreadWrapperInterface;
}
