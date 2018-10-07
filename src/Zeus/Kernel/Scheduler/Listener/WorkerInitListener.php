<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\Event\WorkerExited;
use Zeus\Kernel\Scheduler\Event\WorkerProcessingStarted;
use Zeus\Kernel\Scheduler\Event\WorkerProcessingFinished;
use Zend\EventManager\EventManagerInterface;

class WorkerInitListener
{
    /** @var EventManagerInterface **/
    private $eventManager;

    /** @var IpcServer */
    private $ipc;

    /** @var LoggerInterface */
    private $logger;
    
    public function __construct(LoggerInterface $logger, EventManagerInterface $eventManager, IpcServer $ipc)
    {
        $this->eventManager = $eventManager;
        $this->ipc = $ipc;
        $this->logger = $logger;
    }
    
    public function __invoke(WorkerEvent $event)
    {
        $eventManager = $this->eventManager;
        $statusSender = new WorkerStatusSender($this->logger, $this->ipc);
        $events[] = $eventManager->attach(WorkerProcessingStarted::class, $statusSender, WorkerEvent::PRIORITY_FINALIZE + 1);
        $events[] = $eventManager->attach(WorkerProcessingFinished::class, $statusSender, WorkerEvent::PRIORITY_FINALIZE + 1);
        $events[] = $eventManager->attach(WorkerExited::class, $statusSender, WorkerEvent::PRIORITY_FINALIZE + 2);
    }
}