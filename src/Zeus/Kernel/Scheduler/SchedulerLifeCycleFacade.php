<?php

namespace Zeus\Kernel\Scheduler;

use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\SchedulerInterface;
use Zeus\Kernel\Scheduler\Command\CreateWorker;
use Zeus\Kernel\Scheduler\Command\TerminateScheduler;
use Zeus\Kernel\Scheduler\Command\InitializeWorker;

use function class_exists;

/**
 * Class SchedulerLifeCycleFacade
 * @package Zeus\Kernel\Scheduler
 * @internal
 */
class SchedulerLifeCycleFacade extends AbstractLifeCycleFacade
{
    public function start(array $startParams)
    {
        $worker = $this->getNewWorker();
        $worker->setIsLastTask(false);

        $startParams[SchedulerInterface::WORKER_SERVER] = true;

        $event = $this->triggerEvent(CreateWorker::class, $startParams, $worker);
        if (!$event->getParam(SchedulerInterface::WORKER_INIT, false)) {
            return;
        }
        
        $params = $event->getParams();
        $params[SchedulerInterface::WORKER_SERVER] = true;
        $this->triggerEvent(InitializeWorker::class, $params, $worker);        
    }

    public function stop(WorkerState $worker, bool $isSoftStop)
    {
        $uid = $worker->getUid();

        $worker->setTime(microtime(true));
        $worker->setCode(WorkerState::TERMINATED);

        $worker = new WorkerState('unknown', WorkerState::TERMINATED);
        $worker->setUid($uid);

        $this->triggerEvent(TerminateScheduler::class, ['isSoftStop' => $isSoftStop], $worker);
    }

    private function triggerEvent(string $eventName, $params, WorkerState $worker = null) : SchedulerEvent
    {
        if (class_exists($eventName)) {
            $event = new $eventName();
        } else {
            $event = new WorkerEvent();
            $event->setName($eventName);
        }
        
        $this->initWorkerEvent($event);

        if ($params) {
            $event->setParams($params);
        }
        
        if ($worker) {
            $event->setWorker($worker);
            $this->getScheduler()->setWorker($worker);
        }
        
        $event->setTarget($worker ? $worker : $this->getScheduler()->getWorker());
        $event->setScheduler($this->getScheduler());

        $this->getScheduler()->getEventManager()->triggerEvent($event);

        return $event;
    }
}