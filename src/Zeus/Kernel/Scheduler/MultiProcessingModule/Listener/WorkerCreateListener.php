<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\MultiProcessingModule\ModuleDecorator;
use Zeus\Kernel\Scheduler\WorkerEvent;

class WorkerCreateListener extends AbstractWorkerPoolListener
{
    public function __invoke(WorkerEvent $event)
    {
        $this->driver->onWorkerCreate($event);
        
        if (!$event->getParam('initWorker', false)) {
            $this->workerPool->registerWorker($event->getWorker()->getUid(), $event->getParam(ModuleDecorator::ZEUS_IPC_PIPE_PARAM));
        }
    }
}