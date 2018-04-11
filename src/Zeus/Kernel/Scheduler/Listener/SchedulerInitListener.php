<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\SchedulerLifeCycleFacade;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\SchedulerInterface;

class SchedulerInitListener
{
    /** @var SchedulerLifeCycleFacade */
    private $schedulerLifeCycle;

    public function __construct(SchedulerLifeCycleFacade $schedulerLifeCycle)
    {
        $this->schedulerLifeCycle = $schedulerLifeCycle;
    }

    public function __invoke(WorkerEvent $event)
    {
        if (!$event->getParam(SchedulerInterface::WORKER_SERVER)) {
            return;
        }
        $event->stopPropagation(true);
        $this->schedulerLifeCycle->start([]);
        $event->getWorker()->setIsLastTask(true);

        $event->getScheduler()->getEventManager()->attach(WorkerEvent::EVENT_EXIT, function(WorkerEvent $event) {
            $event->stopPropagation(true);
        }, SchedulerEvent::PRIORITY_INITIALIZE + 2);

    }
}