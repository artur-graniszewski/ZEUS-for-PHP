<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\Event\WorkerExited;
use Zeus\Kernel\Scheduler\Event\WorkerProcessingStarted;
use Zeus\Kernel\Scheduler\Event\WorkerProcessingFinished;
use Zend\EventManager\EventManagerInterface;

class WorkerInitListener
{
    /** @var EventManagerInterface **/
    private $eventManager;
    
    public function __construct(EventManagerInterface $eventManager)
    {
        $this->eventManager = $eventManager;
    }
    
    public function __invoke(WorkerEvent $event)
    {
        $eventManager = $this->eventManager;
        $statusSender = new WorkerStatusSender();
        $events[] = $eventManager->attach(WorkerProcessingStarted::class, $statusSender, WorkerEvent::PRIORITY_FINALIZE + 1);
        $events[] = $eventManager->attach(WorkerProcessingFinished::class, $statusSender, WorkerEvent::PRIORITY_FINALIZE + 1);
        $events[] = $eventManager->attach(WorkerExited::class, $statusSender, WorkerEvent::PRIORITY_FINALIZE + 2);
    }
}