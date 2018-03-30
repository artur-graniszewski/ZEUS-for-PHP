<?php

namespace Zeus\Kernel\Scheduler;

use Error;
use Throwable;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\SchedulerInterface;

/**
 * @internal
 */
class WorkerLifeCycleFacade extends AbstractLifeCycleFacade
{
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

    public function start(array $startParams)
    {
        $this->triggerEvent(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, $startParams);

        $worker = $this->getNewWorker();
        $worker->setIsLastTask(false);

        // worker create...
        $event = $this->triggerEvent(WorkerEvent::EVENT_CREATE, $startParams, $worker);

        if (!$event->getParam(SchedulerInterface::WORKER_INIT)) {
            return;
        }

        $params = $event->getParams();

        // worker init...
        $worker = $event->getWorker();
        $event = $this->triggerEvent(WorkerEvent::EVENT_INIT, $params, $worker);

        if (!$event->propagationIsStopped()) {
            $this->mainLoop($event->getWorker());
        }

        // worker exit...
        $worker = $event->getWorker();
        $this->triggerEvent(WorkerEvent::EVENT_EXIT, $params, $worker);
    }

    public function stop(WorkerState $worker, bool $isSoftStop)
    {
        $uid = $worker->getUid();

        $params = [
            'uid' => $uid,
            'soft' => $isSoftStop
        ];

        $this->triggerEvent(WorkerEvent::EVENT_TERMINATE, $params);
    }

    private function mainLoop(WorkerState $worker)
    {
        $worker->setWaiting();
        $this->syncWorker($worker);

        // handle only a finite number of requests and terminate gracefully to avoid potential memory/resource leaks
        while (($runsLeft = $this->getScheduler()->getConfig()->getMaxProcessTasks() - $worker->getNumberOfFinishedTasks()) > 0) {
            $worker->setIsLastTask($runsLeft === 1);
            try {
                $params = [
                    'status' => $worker
                ];

                $this->triggerEvent(WorkerEvent::EVENT_LOOP, $params, $worker);

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

        $this->triggerEvent(WorkerEvent::EVENT_EXIT, $params, $worker);
    }

    public function syncWorker(WorkerState $worker)
    {
        $params = [
            'status' => $worker
        ];

        $this->triggerEvent($worker->getCode() === WorkerState::RUNNING ? WorkerEvent::EVENT_RUNNING : WorkerEvent::EVENT_WAITING, $params, $worker);
    }

    private function triggerEvent(string $eventName, $params, WorkerState $worker = null) : WorkerEvent
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
}