<?php

namespace Zeus\Kernel\Scheduler;

use Throwable;
use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\Scheduler\Helper\GarbageCollector;
use Zeus\Kernel\Scheduler\Status\StatusMessage;
use Zeus\Kernel\Scheduler\Status\WorkerState;

use function time;
use function addcslashes;
use function get_class;

/**
 * Class Worker
 * @package Zeus\Kernel\Scheduler
 * @internal
 */
class Worker
{
    use GarbageCollector;

    /** @var WorkerState */
    protected $status;

    /** @var int */
    protected $processId;

    /** @var int */
    protected $threadId = 1;

    /** @var EventManagerInterface */
    protected $events;

    /** @var LoggerInterface */
    protected $logger;

    /** @var ConfigInterface */
    protected $config;

    /** @var IpcServer */
    protected $ipcAdapter;

    /** @var bool */
    protected $isTerminating = false;

    /**
     * @param int $processId
     * @return $this
     */
    public function setProcessId(int $processId)
    {
        $this->processId = $processId;

        return $this;
    }

    /**
     * @param bool $isTerminating
     * @return $this
     */
    public function setIsTerminating(bool $isTerminating)
    {
        $this->isTerminating = $isTerminating;

        return $this;
    }

    /**
     * @return bool
     */
    public function isTerminating() : bool
    {
        return $this->isTerminating;
    }

    /**
     * @param int $threadId
     * @return $this
     */
    public function setThreadId(int $threadId)
    {
        $this->threadId = $threadId;

        return $this;
    }

    /**
     * @return int
     */
    public function getThreadId(): int
    {
        return $this->threadId;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @return int
     */
    public function getProcessId() : int
    {
        return $this->processId;
    }

    /**
     * @return WorkerState
     */
    public function getStatus()
    {
        if (!$this->status) {
            $this->status = new WorkerState($this->getConfig()->getServiceName());
            $this->status->setProcessId($this->getProcessId());
        }

        return $this->status;
    }

    /**
     * @return ConfigInterface
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param ConfigInterface $config
     * @return $this
     */
    public function setConfig(ConfigInterface $config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function setEventManager(EventManagerInterface $events)
    {
        $this->events = $events;

        return $this;
    }

    /**
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        if (null === $this->events) {
            $this->setEventManager(new EventManager());
        }

        return $this->events;
    }

    /**
     * @param $ipcAdapter
     * @return $this
     */
    public function setIpc($ipcAdapter)
    {
        $this->ipcAdapter = $ipcAdapter;

        return $this;
    }

    /**
     * @return IpcServer
     */
    public function getIpc()
    {
        return $this->ipcAdapter;
    }

    /**
     * @param EventManagerInterface $eventManager
     * @return $this
     */
    public function attach(EventManagerInterface $eventManager)
    {
        $eventManager = $this->getEventManager();
        $eventManager->attach(WorkerEvent::EVENT_WORKER_INIT, function(WorkerEvent $event) use ($eventManager) {
            set_exception_handler([$this, 'terminate']);

            $eventManager->attach(WorkerEvent::EVENT_WORKER_RUNNING, function(WorkerEvent $e) {
                $this->sendStatus($e);
            }, SchedulerEvent::PRIORITY_FINALIZE + 1);

            $eventManager->attach(WorkerEvent::EVENT_WORKER_WAITING, function(WorkerEvent $e) {
                $this->sendStatus($e);
            }, SchedulerEvent::PRIORITY_FINALIZE + 1);

            $eventManager->attach(WorkerEvent::EVENT_WORKER_EXIT, function(WorkerEvent $e) {
                $this->sendStatus($e);
            }, SchedulerEvent::PRIORITY_FINALIZE + 2);

            $eventManager->attach(WorkerEvent::EVENT_WORKER_EXIT, function(WorkerEvent $e) {
                $this->onExit($e);
            }, SchedulerEvent::PRIORITY_FINALIZE);

        }, WorkerEvent::PRIORITY_FINALIZE + 1);


        $eventManager->attach(WorkerEvent::EVENT_WORKER_INIT, function(WorkerEvent $event) {
            $event->getWorker()->mainLoop();
        }, WorkerEvent::PRIORITY_FINALIZE);

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
        $event->setName(WorkerEvent::EVENT_WORKER_RUNNING);
        $event->setParam('status', $status);
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
        $event->setWorker($this);
        $status->setTime($now);
        $status->setStatusDescription($statusDescription);
        $status->setCode(WorkerState::WAITING);
        $event->setName(WorkerEvent::EVENT_WORKER_WAITING);
        $event->setParam('status', $status);
        $this->getEventManager()->triggerEvent($event);

        return $this;
    }

    /**
     * @param \Throwable $exception
     * @return $this
     */
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

        return $this;
    }

    /**
     * @param \Throwable|null $exception
     */
    protected function terminate(\Throwable $exception = null)
    {
        $status = $this->getStatus();

        // process is terminating, time to live equals zero
        $this->getLogger()->debug(sprintf("Shutting down after finishing %d tasks", $status->getNumberOfFinishedTasks()));

        $status->setCode(WorkerState::EXITING);

        $payload = $status->toArray();

        if ($exception) {
            $payload['exception'] = $exception;
        }

        $event = new WorkerEvent();
        $event->setTarget($this);
        $event->setName(WorkerEvent::EVENT_WORKER_EXIT);
        $event->setParams($payload); // @todo: remove this line?
        $event->setParam('status', $status);

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
                $event->setWorker($this);
                $event->setName(WorkerEvent::EVENT_WORKER_LOOP);
                $event->setParams($status->toArray());
                $this->getEventManager()->triggerEvent($event);

            } catch (\Error $exception) {
                $this->reportException($exception);
                $this->terminate();
            } catch (\Throwable $exception) {
                $this->reportException($exception);
            }

            if ($event->isWorkerStopping()) {
                break;
            }
        }

        $this->terminate();
    }

    /**
     * @param WorkerEvent $event
     * @return $this
     * @todo: move this to an AbstractProcess or a Plugin?
     */
    protected function sendStatus(WorkerEvent $event)
    {
        $status = $event->getTarget()->getStatus();
        $status->updateStatus();
        $worker = $event->getTarget();

        $payload = [
            'type' => Message::IS_STATUS,
            'message' => $status->getStatusDescription(),
            'extra' => [
                'uid' => $worker->getProcessId(),
                'threadId' => $worker->getThreadId(),
                'processId' => $worker->getProcessId(),
                'logger' => __CLASS__,
                'status' => $status->toArray()
            ]
        ];

        $message = new StatusMessage($payload);

        try {
            $worker->getIpc()->send($message, IpcServer::AUDIENCE_SERVER);
        } catch (\Exception $ex) {
            $event->stopWorker(true);
            $event->setParam('exception', $ex);
        }
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