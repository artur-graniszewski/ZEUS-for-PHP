<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Error;
use Throwable;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\SchedulerInterface;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;
use Zeus\Kernel\Scheduler\ConfigInterface;
use Zeus\Kernel\Scheduler\Event\WorkerLoopRepeated;

class WorkerLifeCycle
{
    use ExceptionLoggerTrait;

    /** @var SchedulerInterface */
    private $scheduler;

    /** @var WorkerEvent */
    private $workerEvent;

    public function __construct(SchedulerInterface $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function __invoke(WorkerEvent $event)
    {
        if (!$event->getParam(SchedulerInterface::WORKER_INIT) || $event->getParam(SchedulerInterface::WORKER_SERVER)) {
            return;
        }

        $event->getScheduler()->setTerminating(true);

        $this->workerEvent = clone $event;
        $this->workerEvent->stopPropagation(false);

        $params = $event->getParams();
        $worker = $event->getWorker();
        $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, $params, $worker);

        if (!$event->propagationIsStopped()) {
            $this->mainLoop($event->getWorker());
        }

        // worker exit...
        $worker = $event->getWorker();
        $this->triggerEvent(WorkerEvent::EVENT_EXIT, $params, $worker);
    }

    private function triggerEvent(string $eventName, $params, WorkerState $worker = null) : WorkerEvent
    {
        $event = $this->scheduler->getWorkerEvent();
        $event->setName($eventName);
        $event->setParams($params);
        $event->setScheduler($this->scheduler);

        if ($worker) {
            $event->setTarget($worker);
            $event->setWorker($worker);
        }

        $this->scheduler->getEventManager()->triggerEvent($event);

        return $event;
    }

    private function mainLoop(WorkerState $worker)
    {
        $worker->setWaiting();
        $this->syncWorker($worker);
        $scheduler = $this->scheduler;
        $config = $this->scheduler->getConfig();
        
        // handle only a finite number of requests and terminate gracefully to avoid potential memory/resource leaks
        while (!$this->isWorkerExiting($config, $worker)) {
            $wasLastTask = $worker->isLastTask();
            try {
                $params = [
                    'status' => $worker
                ];
                $this->triggerEvent(WorkerLoopRepeated::class, $params, $worker);

            } catch (Error $exception) {
                $this->terminate($worker, $exception);
            } catch (Throwable $exception) {
                $this->logException($exception, $scheduler->getLogger());
            }
            
            if ($wasLastTask || $worker->isExiting()) {
                break;
            }
        }

        $this->terminate($worker);
    }
    
    private function isWorkerExiting(ConfigInterface $config, WorkerState $worker) : bool
    {
        if ($worker->isExiting()) {
            true;
        }
        
        $runsLeft = $config->getMaxProcessTasks() - $worker->getNumberOfFinishedTasks();
        $worker->setIsLastTask($runsLeft < 2);
        
        return $runsLeft <= 0;
    }

    private function terminate(WorkerState $worker, Throwable $exception = null)
    {
        $logger = $this->scheduler->getLogger();
        $logger->debug(sprintf("Shutting down after finishing %d tasks", $worker->getNumberOfFinishedTasks()));

        $worker->setCode(WorkerState::EXITING);
        $worker->setTime(time());

        $params = [
            'status' => $worker
        ];

        if ($exception) {
            $this->logException($exception, $logger);
            $params['exception'] = $exception;
        }

        $this->triggerEvent(WorkerEvent::EVENT_EXIT, $params, $worker);
    }

    private function syncWorker(WorkerState $worker)
    {
        $params = [
            'status' => $worker
        ];

        $this->triggerEvent($worker->getCode() === WorkerState::RUNNING ? WorkerEvent::EVENT_RUNNING : WorkerEvent::EVENT_WAITING, $params, $worker);
    }
}