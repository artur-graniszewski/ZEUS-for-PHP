<?php

namespace Zeus\Kernel\Scheduler;

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
        $logger = $this->getScheduler()->getLogger();
        $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, $startParams);
        $this->triggerEvent(SchedulerEvent::EVENT_START, $startParams);
        $this->mainLoop();
        // @fixme: kernelLoop() should be merged with mainLoop()
        $this->triggerEvent(SchedulerEvent::EVENT_STOP, []);
        $logger->notice("Scheduler terminated");
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

        if ($worker) {
            $event->setTarget($worker);
            $event->setScheduler($this->getScheduler());
        }

        $this->getScheduler()->getEventManager()->triggerEvent($event);

        return $event;
    }
}