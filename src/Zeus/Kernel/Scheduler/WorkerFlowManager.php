<?php

namespace Zeus\Kernel\Scheduler;

use Zeus\Kernel\Scheduler;

class WorkerFlowManager
{
    /** @var Scheduler */
    private $scheduler;

    public function setScheduler(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    private function getWorkerEvent(string $eventName) : WorkerEvent
    {
        $event = new WorkerEvent();
        $event->setName($eventName);
        $event->setScheduler($this->scheduler);

        return $event;
    }

    private function getSchedulerEvent(string $eventName) : SchedulerEvent
    {
        $event = new SchedulerEvent();
        $event->setName($eventName);
        $event->setScheduler($this->scheduler);
        $event->setTarget($this->scheduler);

        return $event;
    }

    private function getWorker() : Worker
    {
        $worker = new Worker();
        $worker->setLogger($this->scheduler->getLogger());
        $worker->setConfig($this->scheduler->getConfig());
        $worker->setEventManager($this->scheduler->getEventManager());

        return $worker;
    }

    public function startWorker($eventParameters = [])
    {
        $events = $this->scheduler->getEventManager();

        $worker = $this->getWorker();
        $worker->setIsTerminating(false);

        // worker create...
        $event = $this->getWorkerEvent(WorkerEvent::EVENT_CREATE);
        $event->setWorker($worker);
        $event->setTarget($worker);
        $event->setParams($eventParameters);
        $events->triggerEvent($event);

        if (!$event->getParam('initWorker')) {
            return $this;
        }

        $params = $event->getParams();

        // @fixme: why worker UID must be set after getWorkerEvent and not before? it shouldnt be cloned

        // worker init...
        $worker = $event->getWorker();
        $event = $this->getWorkerEvent(WorkerEvent::EVENT_INIT);
        $event->setParams($params);
        $event->setTarget($worker);
        $event->setWorker($worker);
        $events->triggerEvent($event);

        // worker exit...
        $worker = $event->getWorker();
        $event = $this->getWorkerEvent(WorkerEvent::EVENT_EXIT);
        $event->setParams($params);
        $event->setTarget($worker);
        $event->setWorker($worker);
        $events->triggerEvent($event);
    }

    public function stopWorker(int $uid, bool $isSoftStop)
    {
        $event = $this->getWorkerEvent(WorkerEvent::EVENT_TERMINATE);
        $event->setParam('uid', $uid);
        $event->setParam('soft', $isSoftStop);

        $this->scheduler->getEventManager()->triggerEvent($event);
    }
}