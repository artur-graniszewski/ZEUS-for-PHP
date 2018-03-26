<?php

namespace Zeus\Kernel\Scheduler;

use Error;
use Throwable;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;

/**
 * @internal
 */
class WorkerFlowManager
{
    use ExceptionLoggerTrait;

    /** @var Scheduler */
    private $scheduler;

    public function setScheduler(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function getScheduler() : Scheduler
    {
        return $this->scheduler;
    }

    private function triggerWorkerEvent(string $eventName, $params, WorkerState $worker = null) : WorkerEvent
    {
        $event = $this->getWorkerEvent();
        $event->setName($eventName);
        $event->setParams($params);

        if ($worker) {
            $event->setTarget($worker);
            $event->setWorker($worker);
            $event->setScheduler($this->getScheduler());
        }

        $this->getScheduler()->getEventManager()->triggerEvent($event);

        return $event;
    }

    public function getWorkerEvent() : WorkerEvent
    {
        $event = new WorkerEvent();
        $event->setScheduler($this->getScheduler());
        $event->setWorker(new WorkerState($this->getScheduler()->getConfig()->getServiceName()));

        return $event;
    }

    private function getNewWorker() : WorkerState
    {
        $scheduler = $this->getScheduler();
        $worker = new WorkerState($scheduler->getConfig()->getServiceName());

        return $worker;
    }

    public function startWorker(array $eventParameters)
    {
        $worker = $this->getNewWorker();
        $worker->setIsLastTask(false);

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

    public function workerLoop(WorkerState $worker)
    {
        $worker->setWaiting();
        $this->getScheduler()->syncWorker($worker);

        // handle only a finite number of requests and terminate gracefully to avoid potential memory/resource leaks
        while (($runsLeft = $this->getScheduler()->getConfig()->getMaxProcessTasks() - $worker->getNumberOfFinishedTasks()) > 0) {
            $worker->setIsLastTask($runsLeft === 1);
            try {
                $params = [
                    'status' => $worker
                ];

                $this->triggerWorkerEvent(WorkerEvent::EVENT_LOOP, $params, $worker);

            } catch (Error $exception) {
                $this->terminate($worker, $exception);
            } catch (Throwable $exception) {
                $this->logException($exception, $this->getScheduler()->getLogger());
            }

            if ($worker->getCode() === WorkerState::EXITING) {
                break;
            }
        }

        $this->terminate($worker);
    }

    private function terminate(WorkerState $worker, Throwable $exception = null)
    {
        $logger = $this->getScheduler()->getLogger();
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

        $this->triggerWorkerEvent(WorkerEvent::EVENT_EXIT, $params, $worker);
    }

    public function syncWorker(WorkerState $worker)
    {
        $params = [
            'status' => $worker
        ];

        $this->triggerWorkerEvent($worker->getCode() === WorkerState::RUNNING ? WorkerEvent::EVENT_RUNNING : WorkerEvent::EVENT_WAITING, $params, $worker);
    }
}