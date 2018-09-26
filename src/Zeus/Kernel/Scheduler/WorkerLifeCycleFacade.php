<?php

namespace Zeus\Kernel\Scheduler;

use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\SchedulerInterface;
use Zeus\Kernel\Scheduler\Command\CreateWorker;
use Zeus\Kernel\Scheduler\Command\TerminateWorker;
use Zeus\Kernel\Scheduler\Command\InitializeWorker;

/**
 * @internal
 */
class WorkerLifeCycleFacade extends AbstractLifeCycleFacade
{
    public function start(array $startParams)
    {
        $worker = $this->getNewWorker();
        $worker->setIsLastTask(false);
        $startParams[SchedulerInterface::WORKER_SERVER] = false;

        // worker create...
        $event = $this->triggerEvent(CreateWorker::class, $startParams, $worker);

        if ($event->getParam(SchedulerInterface::WORKER_INIT)) {
            $this->getScheduler()->setWorker($worker);
            $params = $event->getParams();
            $params[SchedulerInterface::WORKER_SERVER] = false;
            $this->triggerEvent(InitializeWorker::class, $params, $worker);
        }
    }

    public function stop(WorkerState $worker, bool $isSoftStop)
    {
        $uid = $worker->getUid();

        $params = [
            'uid' => $uid,
            'soft' => $isSoftStop
        ];

        $this->triggerEvent(TerminateWorker::class, $params, $worker);
    }

    public function syncWorker(WorkerState $worker)
    {
        $params = [
            'status' => $worker
        ];

        $this->triggerEvent($worker->getCode() === WorkerState::RUNNING ? WorkerEvent::EVENT_RUNNING : WorkerEvent::EVENT_WAITING, $params, $worker);
    }

    private function triggerEvent(string $eventName, $params, WorkerState $worker = null) : WorkerEvent
    {
        if (class_exists($eventName)) {
            $event = new $eventName();
        } else {
            $event = new WorkerEvent();
            $event->setName($eventName);
        }
        
        $this->initWorkerEvent($event);
        $event->setParams($params);
        $event->setScheduler($this->getScheduler());

        if ($worker) {
            $event->setTarget($worker);
            $event->setWorker($worker);
        }

        $this->getScheduler()->getEventManager()->triggerEvent($event);

        return $event;
    }
}