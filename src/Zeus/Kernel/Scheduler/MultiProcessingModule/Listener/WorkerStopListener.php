<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\WorkerEvent;

class WorkerStopListener extends AbstractListener
{
    public function __invoke(WorkerEvent $event)
    {
        $this->driver->onWorkerTerminated($event);
    }
}