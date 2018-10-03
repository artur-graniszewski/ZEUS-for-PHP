<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\SchedulerInterface;
use Zeus\Kernel\Scheduler\Command\CreateWorker;

class KernelLoopGenerator
{
    /** @var SchedulerInterface **/
    private $scheduler;
    
    public function __construct(SchedulerInterface $scheduler)
    {
        $this->scheduler = $scheduler;
    }
    
    public function __invoke(CreateWorker $event)
    {
        if (!$event->getParam(SchedulerInterface::WORKER_SERVER) || $event->getParam(SchedulerInterface::WORKER_INIT)) {
            return;
        }

        $this->scheduler->setWorker($event->getWorker());
    }
}