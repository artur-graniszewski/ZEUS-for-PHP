<?php

namespace Zeus\Kernel\ProcessManager;

use Throwable;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\ProcessManager\Helper\GarbageCollector;
use Zeus\Kernel\ProcessManager\Status\WorkerState;

/**
 * Class Worker
 * @package Zeus\Kernel\ProcessManager
 * @internal
 */
class Worker extends AbstractWorker
{
    use GarbageCollector;

    /**
     * Worker constructor.
     */
    public function __construct()
    {
        set_exception_handler([$this, 'terminate']);
    }

    /**
     * @param EventManagerInterface $eventManager
     * @return $this
     */
    public function attach(EventManagerInterface $eventManager)
    {
        $this->getEventManager()->attach(WorkerEvent::EVENT_WORKER_INIT, function(WorkerEvent $event) {
            $event->getTarget()->mainLoop();
        }, WorkerEvent::PRIORITY_FINALIZE);

        $this->getEventManager()->attach(WorkerEvent::EVENT_WORKER_EXIT, function(WorkerEvent $e) {
            $this->onExit($e);
        }, SchedulerEvent::PRIORITY_FINALIZE);

        return $this;
    }

    /**
     * @param string $statusDescription
     * @return $this
     */
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
        $status->setTime($now);
        $status->setStatusDescription($statusDescription);
        $status->setCode(WorkerState::RUNNING);
        $this->sendStatus();
        $event->setName(WorkerEvent::EVENT_WORKER_RUNNING);
        $event->setParams($status->toArray());
        $this->getEventManager()->triggerEvent($event);

        return $this;
    }

    /**
     * @param string $statusDescription
     * @return $this
     */
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
        $status->setTime($now);
        $status->setStatusDescription($statusDescription);
        $status->setCode(WorkerState::WAITING);
        $event->setName(WorkerEvent::EVENT_WORKER_WAITING);
        $event->setParams($status->toArray());
        $this->getEventManager()->triggerEvent($event);
        $this->sendStatus();

        return $this;
    }

    /**
     * @param \Throwable $exception
     * @return $this
     */
    protected function reportException(Throwable $exception)
    {
        $this->getLogger()->err(sprintf("Exception (%d): %s in %s on line %d",
            $exception->getCode(),
            addcslashes($exception->getMessage(), "\t\n\r\0\x0B"),
            $exception->getFile(),
            $exception->getLine()
        ));
        $this->getLogger()->debug(sprintf("Stack Trace:\n%s", $exception->getTraceAsString()));

        return $this;
    }

    /**
     * @param \Throwable|null $exception
     */
    protected function terminate(Throwable $exception = null)
    {
        $status = $this->getStatus();

        // process is terminating, time to live equals zero
        $this->getLogger()->debug(sprintf("Shutting down after finishing %d tasks", $status->getNumberOfFinishedTasks()));

        $status->setCode(WorkerState::EXITING);
        $this->sendStatus();

        $payload = $status->toArray();

        if ($exception) {
            $payload['exception'] = $exception;
        }

        $event = new WorkerEvent();
        $event->setTarget($this);
        $event->setName(WorkerEvent::EVENT_WORKER_EXIT);
        $event->setParams($payload);

        $this->getEventManager()->triggerEvent($event);
    }

    /**
     * Listen for incoming requests.
     */
    public function mainLoop()
    {
        $exception = null;
        $this->setWaiting();
        $status = $this->getStatus();

        // handle only a finite number of requests and terminate gracefully to avoid potential memory/resource leaks
        while ($this->getConfig()->getMaxProcessTasks() - $status->getNumberOfFinishedTasks() > 0) {
            $this->collectCycles();
            $exception = null;
            try {
                $event = new WorkerEvent();
                $event->setTarget($this);
                $event->setName(WorkerEvent::EVENT_WORKER_LOOP);
                $event->setParams($status->toArray());
                $this->getEventManager()->triggerEvent($event);

                if ($event->isWorkerStopping()) {
                    break;
                }
            } catch (\Throwable $exception) {
                $this->reportException($exception);
            }
        }

        $this->terminate();
    }

    /**
     * @return $this
     * @todo: move this to an AbstractProcess or a Plugin?
     */
    protected function sendStatus()
    {
        $status = $this->getStatus();
        $status->updateStatus();

        $payload = [
            'type' => Message::IS_STATUS,
            'message' => $status->getStatusDescription(),
            'extra' => [
                'uid' => $this->getProcessId(),
                'threadId' => $this->getThreadId(),
                'processId' => $this->getProcessId(),
                'logger' => __CLASS__,
                'status' => $status->toArray()
            ]
        ];

        $this->getSchedulerIpc()->send(1, $payload);

        return $this;
    }

    /**
     * @param WorkerEvent $event
     */
    protected function onExit(WorkerEvent $event)
    {
        /** @var \Exception $exception */
        $exception = $event->getParam('exception');

        $status = $exception ? $exception->getCode(): 0;
        exit($status);
    }
}