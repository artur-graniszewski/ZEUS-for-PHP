<?php

namespace Zeus\Kernel\Scheduler;

use Throwable;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\SchedulerInterface;

/**
 * Class SchedulerLifeCycleFacade
 * @package Zeus\Kernel\Scheduler
 * @internal
 */
class SchedulerLifeCycleFacade extends AbstractLifeCycleFacade
{
    public function getSchedulerEvent() : SchedulerEvent
    {
        return $this->getScheduler()->getSchedulerEvent();
    }

    public function start(array $startParams)
    {
        $worker = $this->getNewWorker();
        $worker->setIsLastTask(false);

        $startParams[SchedulerInterface::WORKER_SERVER] = true;

        // worker create...
        $event = $this->triggerEvent(WorkerEvent::EVENT_CREATE, $startParams, $worker);
        if ($event->getParam(SchedulerInterface::WORKER_INIT, false)) {
            $params = $event->getParams();
            $params[SchedulerInterface::WORKER_SERVER] = true;
            $this->triggerEvent(WorkerEvent::EVENT_INIT, $params, $worker);
        }
    }

    public function stop(WorkerState $worker, bool $isSoftStop)
    {
        $uid = $worker->getUid();

        $this->getScheduler()->getLogger()->debug(sprintf('Stopping worker %d', $uid));

        $worker->setTime(microtime(true));
        $worker->setCode(WorkerState::TERMINATED);

        $worker = new WorkerState('unknown', WorkerState::TERMINATED);
        $worker->setUid($uid);

        $this->triggerEvent(SchedulerEvent::EVENT_TERMINATE, ['isSoftStop' => $isSoftStop], $worker);
    }

    private function triggerEvent(string $eventName, $params, WorkerState $worker = null) : SchedulerEvent
    {
        $event = $this->getWorkerEvent();
        $event->setName($eventName);

        if ($params) {
            $event->setParams($params);
        }

        $event->setTarget($worker ? $worker : $this->getScheduler()->getWorker());
        $event->setScheduler($this->getScheduler());

        $this->getScheduler()->getEventManager()->triggerEvent($event);

        return $event;
    }
}