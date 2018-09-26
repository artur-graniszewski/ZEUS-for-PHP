<?php

namespace Zeus\Kernel\Scheduler;

use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\SchedulerInterface;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;

abstract class AbstractLifeCycleFacade
{
    use ExceptionLoggerTrait;

    /** @var SchedulerInterface */
    private $scheduler;

    public function setScheduler(SchedulerInterface $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function getScheduler() : SchedulerInterface
    {
        return $this->scheduler;
    }

    public function initWorkerEvent(SchedulerEvent $event)
    {
        $event->setScheduler($this->getScheduler());
        $event->setTarget($this->getScheduler());
        if ($event instanceof WorkerEvent) {
            $event->setWorker(new WorkerState($this->getScheduler()->getConfig()->getServiceName()));
        }
    }

    protected function getNewWorker() : WorkerState
    {
        $scheduler = $this->getScheduler();
        $worker = new WorkerState($scheduler->getConfig()->getServiceName());

        return $worker;
    }
}