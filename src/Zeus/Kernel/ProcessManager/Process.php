<?php

namespace Zeus\Kernel\ProcessManager;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\Kernel\ProcessManager\Status\ProcessState;

final class Process
{
    /** @var int Time to live before terminating (# of requests left till the auto-shutdown) */
    protected $ttl;

    /** @var ProcessState */
    protected $status;

    /** @var string */
    protected $processId;

    /** @var EventManagerInterface */
    protected $events;

    /** @var LoggerInterface */
    protected $logger;

    /** @var SchedulerEvent */
    protected $event;

    /**
     * @param string $processId
     * @return $this
     */
    public function setId($processId)
    {
        $this->processId = $processId;

        return $this;
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
     * @param EventManagerInterface $eventManager
     * @return $this
     */
    public function setEventManager(EventManagerInterface $eventManager)
    {
        $this->events = $eventManager;

        return $this;
    }

    /**
     * @param ConfigInterface $config
     * @return $this
     */
    public function setConfig(ConfigInterface $config)
    {
        // set time to live counter
        $this->ttl = $config->getMaxProcessTasks();
        $this->status = new ProcessState($config->getServiceName());

        return $this;
    }

    /**
     * Process constructor.
     * @param \Zeus\Kernel\ProcessManager\SchedulerEvent $event
     */
    public function __construct(SchedulerEvent $event)
    {
        $this->event = $event;
        $event->setProcess($this);
        set_exception_handler([$this, 'terminateProcess']);
    }

    /**
     * @param int $type
     * @param mixed $message
     * @return $this
     */
    protected function sendMessage($type, $message)
    {
        $payload = [
            'isEvent' => false,
            'type' => $type,
            'priority' => $type,
            'message' => $message,
            'extra' => [
                'uid' => $this->getId(),
                'logger' => __CLASS__,
                'status' => $this->status->toArray()
            ]
        ];

        $event = $this->event;
        $event->setParams($payload);
        $event->setName(SchedulerEvent::EVENT_PROCESS_MESSAGE);
        $this->events->triggerEvent($event);

        return $this;
    }

    /**
     * @param string $statusDescription
     * @return $this
     */
    public function setRunning($statusDescription = null)
    {
        if ($this->status->getCode() === ProcessState::RUNNING) {
            if ($statusDescription === $this->status->getStatusDescription()) {
                return $this;
            }
        } else {
            $this->getStatus()->incrementNumberOfFinishedTasks(1);
        }

        $this->status->setStatusDescription($statusDescription);
        $this->sendStatus(ProcessState::RUNNING, $statusDescription);
        $event = $this->event;
        $event->setName(SchedulerEvent::EVENT_PROCESS_RUNNING);
        $event->setParams($this->status->toArray());
        $this->events->triggerEvent($event);

        return $this;
    }

    /**
     * @param string $statusDescription
     * @return $this
     */
    public function setWaiting($statusDescription = null)
    {
        if ($this->status->getCode() === ProcessState::WAITING
            &&
            $statusDescription === $this->status->getStatusDescription()
        ) {
            return $this;
        }

        $this->status->setStatusDescription($statusDescription);
        $event = $this->event;
        $event->setName(SchedulerEvent::EVENT_PROCESS_WAITING);
        $event->setParams($this->status->toArray());
        $this->events->triggerEvent($event);
        $this->sendStatus(ProcessState::WAITING, $statusDescription);

        return $this;
    }

    /**
     * @param \Exception $exception
     * @return $this
     */
    protected function reportException($exception)
    {
        $this->logger->err(sprintf("Exception (%d): %s in %s on line %d",
            $exception->getCode(),
            addcslashes($exception->getMessage(), "\t\n\r\0\x0B"),
            $exception->getFile(),
            $exception->getLine()
        ));
        $this->logger->debug(sprintf("Stack Trace:\n%s", $exception->getTraceAsString()));

        return $this;
    }

    /**
     * @param \Exception|\Throwable|null $exception
     */
    protected function terminateProcess($exception = null)
    {
        // child is dying, time to live equals zero
        // wake up the ServerDaemon to inform him that this child is dying
        $this->logger->debug(sprintf("Shutting down after finishing %d tasks", $this->status->getNumberOfFinishedTasks()));

        $this->ttl = 0;

        $this->sendStatus(ProcessState::EXITING);

        $payload = $this->status->toArray();

        if ($exception) {
            $payload['exception'] = $exception;
        }

        $event = $this->event;
        $event->setName(SchedulerEvent::EVENT_PROCESS_EXIT);
        $event->setParams($payload);

        $this->events->triggerEvent($event);
    }

    /**
     * Listen for incoming requests.
     *
     * @return $this
     */
    public function mainLoop()
    {
        $this->events->attach(SchedulerEvent::EVENT_PROCESS_LOOP, function(EventInterface $event) {
            $this->sendStatus($this->status->getCode());
        });

        $exception = null;
        $this->setWaiting();

        // handle only a finite number of requests and terminate gracefully to avoid potential memory/resource leaks
        while ($this->ttl - $this->status->getNumberOfFinishedTasks() > 0) {
            $exception = null;
            try {
                $event = $this->event;
                $event->setName(SchedulerEvent::EVENT_PROCESS_LOOP);
                $event->setParams($this->status->toArray());
                $this->events->triggerEvent($event);
            } catch (\Exception $exception) {
                $this->reportException($exception);
            } catch (\Throwable $exception) {
                $this->reportException($exception);
            }
            $this->setWaiting($this->getStatus()->getStatusDescription());
        }

        $this->terminateProcess();
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->processId;
    }

    /**
     * @param int $statusCode
     * @param string $statusDescription
     * @return $this
     */
    protected function sendStatus($statusCode, $statusDescription = null)
    {
        $oldStatus = $this->status->getCode();
        $this->status->setCode($statusCode);
        $this->status->updateStatus();

        // send new status to Scheduler only if it changed
        if ($oldStatus !== $statusCode) {
            $this->sendMessage(Message::IS_STATUS, $statusDescription ? $statusDescription : '');
        }

        return $this;
    }

    /**
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        return $this->events;
    }

    /**
     * @return ProcessState
     */
    public function getStatus()
    {
        return $this->status;
    }
}