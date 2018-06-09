<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;

class KernelStopListener extends AbstractWorkerPoolListener
{
    public function __invoke(SchedulerEvent $event)
    {
        $this->workerPool->shutdown();

        $this->driver->onKernelStop($event);
        $this->driver->onWorkersCheck($event);
    }
}