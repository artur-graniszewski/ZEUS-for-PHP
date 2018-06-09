<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\WorkerEvent;

class WorkerTerminateListener extends AbstractWorkerPoolListener
{
    public function __invoke(WorkerEvent $event)
    {
        $this->driver->onWorkerTerminate($event);
        $this->workerPool->unregisterWorker($event->getParam('uid'));
    }
}