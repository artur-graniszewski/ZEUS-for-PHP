<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Throwable;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\SchedulerInterface;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;

class SchedulerLifeCycle
{
    use ExceptionLoggerTrait;

    /** @var SchedulerInterface */
    private $scheduler;

    private $initialized = false;

    public function getScheduler() : SchedulerInterface
    {
        return $this->scheduler;
    }

    public function __construct(SchedulerInterface $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function __invoke(WorkerEvent $event)
    {
        if (!$event->getParam(SchedulerInterface::WORKER_SERVER) || !$event->getParam(SchedulerInterface::WORKER_INIT)) {
            return;
        }

        $this->initialized = true;
        $event->stopPropagation(true);

        $params = $event->getParams();

        $exception = null;
        try {
            $this->triggerEvent(SchedulerEvent::EVENT_START, $params);
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
        $event = $this->getScheduler()->getSchedulerEvent();
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