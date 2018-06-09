<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\WorkerEvent;

class WorkerInitListener extends AbstractListener
{
    public function __invoke(WorkerEvent $event)
    {
        $this->driver->onWorkerInit($event);
    }
}