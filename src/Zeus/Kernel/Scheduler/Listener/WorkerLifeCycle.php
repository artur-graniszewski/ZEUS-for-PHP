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
use Zeus\Kernel\Scheduler\Event\WorkerExited;
use Zeus\Kernel\Scheduler\Event\WorkerProcessingStarted;
use Zeus\Kernel\Scheduler\Event\WorkerProcessingFinished;
use Zeus\Kernel\Scheduler\Command\InitializeWorker;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;

class WorkerLifeCycle
{
    use ExceptionLoggerTrait;

    /** @var SchedulerInterface */
    private $scheduler;
    
    /** @var EventManagerInterface **/
    private $eventManager;
    
    /** @var LoggerInterface **/
    private $logger;

    /** @var WorkerEvent */
    private $workerEvent;

    public function __construct(LoggerInterface $logger, SchedulerInterface $scheduler, EventManagerInterface $eventManager)
    {
        $this->eventManager = $eventManager;
        $this->scheduler = $scheduler;
        $this->logger = $logger;
    }

    public function __invoke(InitializeWorker $event)
    {
        if (!$event->getParam(SchedulerInterface::WORKER_INIT) || $event->getParam(SchedulerInterface::WORKER_SERVER)) {
            return;
        }

        $event->getScheduler()->setTerminating(true);

        $this->workerEvent = clone $event;
        $this->workerEvent->stopPropagation(false);

        $params = $event->getParams();
        $worker = $event->getWorker();
        
        $diff = time() - $event->getParam('WorkerCreateTime', time());
        if ($diff > 2) {
            $logger = $this->scheduler->getLogger();
            $logger->warn(sprintf("Worker %d started in %d seconds", $worker->getUid(), $diff));
        }
        
        $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, $params, $worker);

        if (!$event->propagationIsStopped()) {
            $this->mainLoop($event->getWorker());
        }

        // worker exit...
        $worker = $event->getWorker();
        $this->triggerEvent(WorkerExited::class, $params, $worker);
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

        $this->eventManager->triggerEvent($event);

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
        $this->logger->debug(sprintf("Shutting down after finishing %d tasks", $worker->getNumberOfFinishedTasks()));

        $worker->setCode(WorkerState::EXITING);
        $worker->setTime(time());

        $params = [
            'status' => $worker
        ];

        if ($exception) {
            $this->logException($exception, $this->logger);
            $params['exception'] = $exception;
        }

        // @todo: this should be triggered only in one place: __invoke()
        $this->triggerEvent(WorkerExited::class, $params, $worker);
    }

    private function syncWorker(WorkerState $worker)
    {
        $params = [
            'status' => $worker
        ];

        $this->triggerEvent($worker->getCode() === WorkerState::RUNNING ? WorkerProcessingStarted::class : WorkerProcessingFinished::class, $params, $worker);
    }
}