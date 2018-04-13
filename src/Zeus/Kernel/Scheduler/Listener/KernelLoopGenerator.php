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

        $scheduler = $event->getScheduler();

        $reactor = $scheduler->getReactor();

        $terminator = function() use ($reactor, $scheduler) {
            $event = $scheduler->getSchedulerEvent();
            $event->setName(SchedulerEvent::INTERNAL_EVENT_KERNEL_LOOP);
            $scheduler->getEventManager()->triggerEvent($event);
            if ($scheduler->isTerminating()) {
                $reactor->setTerminating(true);
            }
        };
        do {
            $reactor->mainLoop(
                $terminator
            );
        } while (!$scheduler->isTerminating());
    }
}