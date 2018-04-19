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

    public function getWorkerEvent() : WorkerEvent
    {
        $event = new WorkerEvent();
        $event->setScheduler($this->getScheduler());
        $event->setTarget($this->getScheduler());
        $event->setWorker(new WorkerState($this->getScheduler()->getConfig()->getServiceName()));

        return $event;
    }

    protected function getNewWorker() : WorkerState
    {
        $scheduler = $this->getScheduler();
        $worker = new WorkerState($scheduler->getConfig()->getServiceName());

        return $worker;
    }
}