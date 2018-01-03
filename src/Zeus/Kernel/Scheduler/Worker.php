<?php

namespace Zeus\Kernel\Scheduler;

use Throwable;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\Scheduler\Helper\GarbageCollector;
use Zeus\Kernel\Scheduler\Status\WorkerState;

use function time;
use function addcslashes;
use function get_class;

/**
 * Class Worker
 * @package Zeus\Kernel\Scheduler
 * @internal
 */
class Worker extends AbstractService
{
    use GarbageCollector;

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

    public function setRunning(string $statusDescription = null)
    {
        $status = $this->getStatus();
        $now = time();
        if ($status->getCode() === WorkerState::RUNNING) {
            if ($statusDescription === $status->getStatusDescription() && $status->getTime() === $now) {
                return $this;
            }
        }

        $event = new WorkerEvent();
        $event->setTarget($this);
        $event->setWorker($this);
        $status->setTime($now);
        if (null !== $statusDescription) {
            $status->setStatusDescription($statusDescription);
        }
        $status->setCode(WorkerState::RUNNING);
        $event->setName(WorkerEvent::EVENT_RUNNING);
        $event->setParam('status', $status);
        $this->getEventManager()->triggerEvent($event);
    }

    public function setWaiting(string $statusDescription = null)
    {
        $status = $this->getStatus();
        $now = time();
        if ($status->getCode() === WorkerState::WAITING) {
            if ($statusDescription === $status->getStatusDescription() && $status->getTime() === $now) {
                return $this;
            }
        }

        $event = new WorkerEvent();
        $event->setTarget($this);
        $event->setWorker($this);
        $status->setTime($now);
        if (null !== $statusDescription) {
            $status->setStatusDescription($statusDescription);
        }
        $status->setCode(WorkerState::WAITING);
        $event->setName(WorkerEvent::EVENT_WAITING);
        $event->setParam('status', $status);
        $this->getEventManager()->triggerEvent($event);
    }

    protected function reportException(Throwable $exception)
    {
        $this->getLogger()->err(sprintf("%s (%d): %s in %s on line %d",
            get_class($exception),
            $exception->getCode(),
            addcslashes($exception->getMessage(), "\t\n\r\0\x0B"),
            $exception->getFile(),
            $exception->getLine()
        ));
        $this->getLogger()->debug(sprintf("Stack Trace:\n%s", $exception->getTraceAsString()));
    }

    public function terminate(\Throwable $exception = null)
    {
        $status = $this->getStatus();

        // process is terminating, time to live equals zero
        $this->getLogger()->debug(sprintf("Shutting down after finishing %d tasks", $status->getNumberOfFinishedTasks()));

        $status->setCode(WorkerState::EXITING);

        $payload = $status->toArray();

        if ($exception) {
            $payload['exception'] = $exception;
            $this->reportException($exception);
        }

        $event = new WorkerEvent();
        $event->setTarget($this);
        $event->setWorker($this);
        $event->setName(WorkerEvent::EVENT_EXIT);
        $event->setParams($payload); // @todo: remove this line?
        $event->setParam('status', $status);

        $this->getEventManager()->triggerEvent($event);
    }

    public function mainLoop()
    {
        $exception = null;
        $this->setWaiting();
        $status = $this->getStatus();

        // handle only a finite number of requests and terminate gracefully to avoid potential memory/resource leaks
        while (($runsLeft = $this->getConfig()->getMaxProcessTasks() - $status->getNumberOfFinishedTasks()) > 0) {
            $status->setIsLastTask($runsLeft === 1);
            $this->collectCycles();
            $exception = null;
            try {
                $event = new WorkerEvent();
                $event->setTarget($this);
                $event->setWorker($this);
                $event->setName(WorkerEvent::EVENT_LOOP);
                $event->setParams($status->toArray());
                $this->getEventManager()->triggerEvent($event);

            } catch (\Error $exception) {
                $this->terminate($exception);
            } catch (\Throwable $exception) {
                $this->reportException($exception);
            }

            if ($this->isTerminating()) {
                break;
            }
        }

        $this->terminate();
    }
}