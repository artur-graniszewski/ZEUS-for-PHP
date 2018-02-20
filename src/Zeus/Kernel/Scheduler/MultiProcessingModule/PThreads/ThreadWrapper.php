<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads;

use Thread;

/**
 * Class ThreadWrapper
 * @package Zeus\Kernel\Scheduler\MultiProcessingModule\PThreads
 * @internal
 */
class ThreadWrapper extends Thread implements ThreadWrapperInterface
{
    use ThreadTrait;
}