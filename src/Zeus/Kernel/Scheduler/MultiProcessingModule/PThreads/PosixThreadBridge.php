<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads;

use Thread;

/**
 * Class PosixThreadBridge
 * @package Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads
 * @internal
 */
class PosixThreadBridge implements PosixThreadBridgeInterface
{

    public function isSupported(): bool
    {
        return class_exists(Thread::class);
    }

    public function getNewThread() : ThreadWrapperInterface
    {
        return new ThreadWrapper();
    }
}