<?php

namespace Zeus\Kernel\Scheduler;

use Error;
use Throwable;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;

use function time;

/**
 * @internal
 */
final class Worker extends AbstractService
{
    use ExceptionLoggerTrait;

    /** @var WorkerState */
    private $status;

    /** @var int */
    private $processId;

    /** @var int */
    private $threadId = 1;

    /** @var int */
    private $uid;

    public function setUid(int $id)
    {
        $this->uid = $id;
    }

    public function getUid() : int
    {
        return $this->uid;
    }

    public function setProcessId(int $processId)
    {
        $this->processId = $processId;
    }

    public function setThreadId(int $threadId)
    {
        $this->threadId = $threadId;
    }

    public function getThreadId(): int
    {
        return $this->threadId;
    }

    public function getProcessId() : int
    {
        return $this->processId;
    }

    public function getStatus() : WorkerState
    {
        if (!$this->status) {
            $this->status = new WorkerState($this->getConfig()->getServiceName());
            $this->status->setProcessId($this->getProcessId());
        }

        return $this->status;
    }

    private function triggerStatusChange(string $statusDescription = '', int $statusCode)
    {
        $status = $this->getStatus();
        $now = time();
        if ($status->getCode() === $statusCode) {
            if ($statusDescription === $status->getStatusDescription() && $status->getTime() === $now) {
                return;
            }
        }

        $status->setTime($now);
        $status->setCode($statusCode);
        $status->setStatusDescription($statusDescription);

        $params = [
            'status' => $status
        ];

        $this->triggerWorkerEvent($statusCode === WorkerState::RUNNING ? WorkerEvent::EVENT_RUNNING : WorkerEvent::EVENT_WAITING, $params);
    }

    public function setRunning(string $statusDescription = '')
    {
        $this->triggerStatusChange($statusDescription, WorkerState::RUNNING);
    }

    public function setWaiting(string $statusDescription = '')
    {
        $this->triggerStatusChange($statusDescription, WorkerState::WAITING);
    }

    public function terminate(Throwable $exception = null)
    {
        $status = $this->getStatus();

        $this->getLogger()->debug(sprintf("Shutting down after finishing %d tasks", $status->getNumberOfFinishedTasks()));

        $status->setCode(WorkerState::EXITING);
        $status->setTime(time());

        $params = [
            'status' => $status
        ];

        if ($exception) {
            $this->logException($exception, $this->getLogger());
            $params['exception'] = $exception;
        }

        $this->triggerWorkerEvent(WorkerEvent::EVENT_EXIT, $params);
    }

    public function mainLoop()
    {
        $this->setWaiting();
        $status = $this->getStatus();

        // handle only a finite number of requests and terminate gracefully to avoid potential memory/resource leaks
        while (($runsLeft = $this->getConfig()->getMaxProcessTasks() - $status->getNumberOfFinishedTasks()) > 0) {
            $status->setIsLastTask($runsLeft === 1);
            try {
                $params = [
                    'status' => $status
                ];

                $this->triggerWorkerEvent(WorkerEvent::EVENT_LOOP, $params);

            } catch (Error $exception) {
                $this->terminate($exception);
            } catch (Throwable $exception) {
                $this->logException($exception, $this->getLogger());
            }

            if ($this->isTerminating()) {
                break;
            }
        }

        $this->terminate();
    }

    private function triggerWorkerEvent(string $eventName, array $params)
    {
        $event = new WorkerEvent();
        $event->setTarget($this);
        $event->setWorker($this);
        $event->setName($eventName);
        $event->setParams($params);
        $this->getEventManager()->triggerEvent($event);
    }
}