<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads;

/**
 * @internal
 */
interface PosixThreadBridgeInterface
{
    public function isSupported() : bool;

    public function getNewThread() : ThreadWrapperInterface;
}
