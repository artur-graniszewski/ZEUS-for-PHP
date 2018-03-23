<?php

namespace Zeus\Kernel\Scheduler;

use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\Status\WorkerState;

/**
 * @internal
 */
class WorkerFlowManager
{
    /** @var Scheduler */
    private $scheduler;

    /** @var bool */
    private $firstWorkerInit = true;

    public function setScheduler(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function getScheduler() : Scheduler
    {
        return $this->scheduler;
    }

    private function triggerWorkerEvent(string $eventName, $params, Worker $worker = null) : WorkerEvent
    {
        $event = new WorkerEvent();
        $event->setName($eventName);
        $event->setParams($params);

        if ($worker) {
            $event->setTarget($worker);
            $event->setWorker($worker);
        }

        $this->getScheduler()->getEventManager()->triggerEvent($event);

        return $event;
    }

    private function getWorker() : Worker
    {
        $scheduler = $this->getScheduler();
        $worker = new Worker();
        $worker->setLogger($scheduler->getLogger());
        $worker->setConfig($scheduler->getConfig());

        if ($this->firstWorkerInit) {
            $worker->setEventManager($scheduler->getEventManager());
            //$this->firstWorkerInit = false;
        }

        return $worker;
    }

    public function startWorker(array $eventParameters)
    {
        $worker = $this->getWorker();
        $worker->setTerminating(false);

        // worker create...
        $event = $this->triggerWorkerEvent(WorkerEvent::EVENT_CREATE, $eventParameters, $worker);

        if (!$event->getParam(Scheduler::WORKER_INIT)) {
            return;
        }

        $params = $event->getParams();

        // worker init...
        $worker = $event->getWorker();
        $this->triggerWorkerEvent(WorkerEvent::EVENT_INIT, $params, $worker);

        // worker exit...
        $worker = $event->getWorker();
        $this->triggerWorkerEvent(WorkerEvent::EVENT_EXIT, $params, $worker);
    }

    public function stopWorker(WorkerState $worker, bool $isSoftStop)
    {
        $uid = $worker->getUid();

        $params = [
            'uid' => $uid,
            'soft' => $isSoftStop
        ];

        $this->triggerWorkerEvent(WorkerEvent::EVENT_TERMINATE, $params);
    }
}