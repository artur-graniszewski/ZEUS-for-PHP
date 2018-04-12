<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\WorkerEvent;

class WorkerInitListener
{
    public function __invoke(WorkerEvent $event)
    {
        $eventManager = $event->getScheduler()->getEventManager();
        $statusSender = new WorkerStatusSender();
        $events[] = $eventManager->attach(WorkerEvent::EVENT_RUNNING, $statusSender, WorkerEvent::PRIORITY_FINALIZE + 1);
        $events[] = $eventManager->attach(WorkerEvent::EVENT_WAITING, $statusSender, WorkerEvent::PRIORITY_FINALIZE + 1);
        $events[] = $eventManager->attach(WorkerEvent::EVENT_EXIT, $statusSender, WorkerEvent::PRIORITY_FINALIZE + 2);
    }
}