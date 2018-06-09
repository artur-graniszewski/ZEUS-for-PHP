<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;

class SchedulerLoopListener extends AbstractWorkerPoolListener
{
    public function __invoke(SchedulerEvent $event)
    {
        $this->driver->onSchedulerLoop($event);
        $wasExiting = $event->getScheduler()->isTerminating();

        $this->workerPool->checkWorkers();
        if (!$this->workerPool->isTerminating()) {
            $this->workerPool->registerWorkers();
        }
        $this->driver->onWorkersCheck($event);

        if ($this->workerPool->isTerminating() && !$wasExiting) {
            $event->getScheduler()->setTerminating(true);
            $event->stopPropagation(true);
        }
    }
}