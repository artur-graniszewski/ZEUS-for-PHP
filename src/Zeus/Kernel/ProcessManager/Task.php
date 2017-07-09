<?php

namespace Zeus\Kernel\ProcessManager;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\ProcessManager\Status\ProcessState;

/**
 * Class Task
 * @package Zeus\Kernel\ProcessManager
 * @internal
 */
class Task extends AbstractTask
{
    /** @var ConfigInterface */
    protected $config;

    /** @var SchedulerEvent */
    protected $event;

    /**
     * Process constructor.
     */
    public function __construct()
    {
        set_exception_handler([$this, 'terminateProcess']);
    }

    /**
     * @param EventManagerInterface $eventManager
     * @return $this
     */
    public function attach(EventManagerInterface $eventManager)
    {
        $this->getEventManager()->attach(TaskEvent::EVENT_PROCESS_INIT, function(TaskEvent $event) {
            // @todo: do not use mainLoop() method from outside
            $this->getIpc()->useChannelNumber(1);
            $event->getTarget()->mainLoop();
        }, TaskEvent::PRIORITY_FINALIZE);

        return $this;
    }

    /**
     * @param ConfigInterface $config
     * @return $this
     */
    public function setConfig(ConfigInterface $config)
    {
        // set time to live counter
        $this->config = $config;

        return $this;
    }

    /**
     * @param string $statusDescription
     * @return $this
     */
    public function setRunning($statusDescription = null)
    {
        $status = $this->getStatus();
        $now = time();
        if ($status->getCode() === ProcessState::RUNNING) {
            if ($statusDescription === $status->getStatusDescription() && $status->getTime() === $now) {
                return $this;
            }
        } else {
            $status->incrementNumberOfFinishedTasks(1);
        }

        $event = new TaskEvent();
        $event->setTarget($this);
        $status->setTime($now);
        $status->setStatusDescription($statusDescription);
        $status->setCode(ProcessState::RUNNING);
        $this->sendStatus();
        $event->setName(TaskEvent::EVENT_PROCESS_RUNNING);
        $event->setParams($status->toArray());
        $this->getEventManager()->triggerEvent($event);

        return $this;
    }

    /**
     * @param string $statusDescription
     * @return $this
     */
    public function setWaiting($statusDescription = null)
    {
        $status = $this->getStatus();
        $now = time();
        if ($status->getCode() === ProcessState::WAITING) {
            if ($statusDescription === $status->getStatusDescription() && $status->getTime() === $now) {
                return $this;
            }
        }

        $event = new TaskEvent();
        $event->setTarget($this);
        $status->setTime($now);
        $status->setStatusDescription($statusDescription);
        $status->setCode(ProcessState::WAITING);
        $event->setName(TaskEvent::EVENT_PROCESS_WAITING);
        $event->setParams($status->toArray());
        $this->getEventManager()->triggerEvent($event);
        $this->sendStatus();

        return $this;
    }

    /**
     * @param \Exception $exception
     * @return $this
     */
    protected function reportException($exception)
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
     * @param \Exception|\Throwable|null $exception
     */
    protected function terminateProcess($exception = null)
    {
        $status = $this->getStatus();

        // process is terminating, time to live equals zero
        $this->getLogger()->debug(sprintf("Shutting down after finishing %d tasks", $status->getNumberOfFinishedTasks()));

        $status->setCode(ProcessState::EXITING);
        $this->sendStatus();

        $payload = $status->toArray();

        if ($exception) {
            $payload['exception'] = $exception;
        }

        $event = new TaskEvent();
        $event->setTarget($this);
        $event->setName(TaskEvent::EVENT_PROCESS_EXIT);
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
            $exception = null;
            try {
                $event = new TaskEvent();
                $event->setTarget($this);
                $event->setName(TaskEvent::EVENT_PROCESS_LOOP);
                $event->setParams($status->toArray());
                $this->getEventManager()->triggerEvent($event);

                if ($event->getParam('exit')) {
                    break;
                }
            } catch (\Throwable $exception) {
                $this->reportException($exception);
            }
        }

        $this->terminateProcess();
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
            'isEvent' => false,
            'type' => Message::IS_STATUS,
            'priority' => Message::IS_STATUS,
            'message' => $status->getStatusDescription(),
            'extra' => [
                'uid' => $this->getProcessId(),
                'threadId' => $this->getThreadId(),
                'processId' => $this->getProcessId(),
                'logger' => __CLASS__,
                'status' => $status->toArray()
            ]
        ];

        $this->getIpc()->send($payload);

        return $this;
    }
}