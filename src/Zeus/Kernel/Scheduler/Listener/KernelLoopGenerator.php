<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\SchedulerInterface;

class KernelLoopGenerator
{
    public function __invoke(WorkerEvent $event)
    {
        if (!$event->getParam(SchedulerInterface::WORKER_SERVER) || $event->getParam(SchedulerInterface::WORKER_INIT)) {
            return;
        }

        $event->getScheduler()->setWorker($event->getWorker());
    }
}