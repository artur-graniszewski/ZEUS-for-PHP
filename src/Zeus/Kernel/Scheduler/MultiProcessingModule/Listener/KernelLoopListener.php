<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;

class KernelLoopListener extends AbstractWorkerPoolListener
{
    public function __invoke(SchedulerEvent $event)
    {
        $this->workerPool->checkWorkers();
        if (!$this->workerPool->isTerminating()) {
            $this->workerPool->registerWorkers();
        }
        $this->driver->onWorkersCheck($event);
        $this->driver->onKernelLoop($event);
    }
}