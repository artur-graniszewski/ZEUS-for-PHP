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

    /** @var bool */
    private $firstWorkerInit = true;

    public function setScheduler(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function getScheduler() : Scheduler
    {
        return $this->scheduler;
    }

    private function triggerWorkerEvent(string $eventName, $params, Worker $worker = null) : WorkerEvent
    {
        $event = new WorkerEvent();
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

    private function getWorker() : Worker
    {
        $scheduler = $this->getScheduler();
        $worker = new Worker();
        $worker->setLogger($scheduler->getLogger());
        $worker->setConfig($scheduler->getConfig());

        if ($this->firstWorkerInit) {
            $worker->setEventManager($scheduler->getEventManager());
            //$this->firstWorkerInit = false;
        }

        return $worker;
    }

    public function startWorker(array $eventParameters)
    {
        $worker = $this->getWorker();
        $worker->setTerminating(false);

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

    public function workerLoop(Worker $worker)
    {
        $worker->setWaiting();
        $this->getScheduler()->syncWorker($worker);
        $status = $worker->getStatus();

        // handle only a finite number of requests and terminate gracefully to avoid potential memory/resource leaks
        while (($runsLeft = $worker->getConfig()->getMaxProcessTasks() - $status->getNumberOfFinishedTasks()) > 0) {
            $status->setIsLastTask($runsLeft === 1);
            try {
                $params = [
                    'status' => $status
                ];

                $this->triggerWorkerEvent(WorkerEvent::EVENT_LOOP, $params, $worker);

            } catch (Error $exception) {
                $this->terminate($worker, $exception);
            } catch (Throwable $exception) {
                $this->logException($exception, $worker->getLogger());
            }

            if ($worker->isTerminating()) {
                break;
            }
        }

        $this->terminate($worker);
    }

    private function terminate(Worker $worker, Throwable $exception = null)
    {
        $status = $worker->getStatus();

        $worker->getLogger()->debug(sprintf("Shutting down after finishing %d tasks", $status->getNumberOfFinishedTasks()));

        $status->setCode(WorkerState::EXITING);
        $status->setTime(time());

        $params = [
            'status' => $status
        ];

        if ($exception) {
            $this->logException($exception, $worker->getLogger());
            $params['exception'] = $exception;
        }

        $this->triggerWorkerEvent(WorkerEvent::EVENT_EXIT, $params, $worker);
    }
}