<?php

namespace Zeus\Kernel\Scheduler;

use Throwable;
use Zeus\Kernel\Scheduler\Status\WorkerState;

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
        $exception = null;

        try {
            $this->triggerEvent(SchedulerEvent::EVENT_START, $startParams);
            $this->mainLoop();
        } catch (Throwable $exception) {
        }
        $params = [];
        if ($exception) {
            $params['exception'] = $exception;
        }

        try {
            $this->triggerEvent(SchedulerEvent::EVENT_STOP, $params);
        } catch (Throwable $e) {
        }

        if ($exception) {
            throw $exception;
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

    /**
     * Starts main (infinite) loop.
     */
    private function mainLoop()
    {
        $scheduler = $this->getScheduler();
        $reactor = $scheduler->getReactor();

        $terminator = function() use ($reactor, $scheduler) {
            $this->triggerEvent(SchedulerEvent::EVENT_LOOP, []);
            if ($scheduler->isTerminating()) {
                $reactor->setTerminating(true);
            }
        };

        do {
            $reactor->mainLoop(
                $terminator
            );
        } while (!$scheduler->isTerminating());
    }

    private function triggerEvent(string $eventName, $params, WorkerState $worker = null) : SchedulerEvent
    {
        $event = $this->getSchedulerEvent();
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