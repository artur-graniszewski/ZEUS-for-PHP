<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Throwable;
use Zeus\Kernel\Scheduler\Command\StartScheduler;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\SchedulerInterface;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;
use Zeus\Kernel\Scheduler\Event\SchedulerLoopRepeated;
use Zeus\Kernel\Scheduler\Command\InitializeWorker;
use Zend\EventManager\EventManagerInterface;

class SchedulerLifeCycle
{
    use ExceptionLoggerTrait;

    /** @var SchedulerInterface */
    private $scheduler;
    
    /** @var EventManagerInterface **/
    private $eventManager;

    private $initialized = false;

    public function getScheduler() : SchedulerInterface
    {
        return $this->scheduler;
    }

    public function __construct(SchedulerInterface $scheduler, EventManagerInterface $eventManager)
    {
        $this->scheduler = $scheduler;
        $this->eventManager = $eventManager;
    }

    public function __invoke(InitializeWorker $event)
    {
        if (!$event->getParam(SchedulerInterface::WORKER_SERVER) || !$event->getParam(SchedulerInterface::WORKER_INIT)) {
            return;
        }

        $this->initialized = true;
        $event->stopPropagation(true);

        $params = $event->getParams();
        $worker = $event->getWorker();

        $exception = null;
        try {
            $this->triggerEvent(StartScheduler::class, $params, $worker);
            $this->mainLoop($worker);
        } catch (Throwable $exception) {
        }

        $params = [];
        if ($exception) {
            $params['exception'] = $exception;
        }

        try {
            $this->triggerEvent(SchedulerEvent::EVENT_STOP, $params, $worker);
        } catch (Throwable $e) {

        }

        if ($exception) {
            throw $exception;
        }
    }

    /**
     * Starts main (infinite) loop.
     */
    private function mainLoop(WorkerState $worker)
    {   
        $scheduler = $this->getScheduler();
        $reactor = $scheduler->getReactor();

        $terminator = function() use ($reactor, $scheduler, $worker) {
            $this->triggerEvent(SchedulerLoopRepeated::class, [], $worker);

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
        if (class_exists($eventName)) {
            $event = new $eventName();
        } else {
            $event = $this->getScheduler()->getSchedulerEvent();
            $event->setName($eventName);
        }

        if ($params) {
            $event->setParams($params);
        }
        
        if ($worker) {
            $event->setWorker($worker);
        }

        $event->setTarget($this->getScheduler());
        $event->setScheduler($this->getScheduler());

        $this->eventManager->triggerEvent($event);

        return $event;
    }
}