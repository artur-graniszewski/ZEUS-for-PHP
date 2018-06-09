<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;

class WorkerLoopListener extends AbstractWorkerPoolListener
{
    public function __invoke(WorkerEvent $event)
    {
        if ($this->workerPool->isTerminating()) {
            $event->getWorker()->setCode(WorkerState::EXITING);
            $event->stopPropagation(true);

            return;
        }
        $this->driver->onWorkerLoop($event);
    }
}