<?php

namespace Zeus\Kernel\ProcessManager;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer\Message;
use Zeus\Kernel\ProcessManager\Status\ProcessState;

/**
 * Class Process
 * @package Zeus\Kernel\ProcessManager
 * @internal
 */
final class Process extends AbstractProcess
{
    /** @var int Time to live before terminating (# of requests left till the auto-shutdown) */
    protected $ttl;

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
        $this->events = $eventManager;
        $this->events->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(SchedulerEvent $event) {
            $config = $event->getScheduler()->getConfig();
            $event->setProcess($this);
            $this->setId($event->getParam('uid'));
            $this->setConfig($config);
            $this->status = new ProcessState($config->getServiceName());
            $this->event = $event;
        }, SchedulerEvent::PRIORITY_INITIALIZE);

        $this->events->attach(SchedulerEvent::EVENT_PROCESS_INIT, function(SchedulerEvent $event) {
            $this->mainLoop();
        }, SchedulerEvent::PRIORITY_FINALIZE);
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

        return $this;
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
            $now = time();
            if ($statusDescription === $this->status->getStatusDescription() && $this->status->getTime() === $now) {
                return $this;
            }

            $this->status->setTime($now);
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
        // process is terminating, time to live equals zero
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
    protected function mainLoop()
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
        }

        $this->terminateProcess();
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
}