<?php

namespace Zeus\Kernel\ProcessManager;

use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\ProcessManager\Status\ProcessState;

/**
 * Class Process
 * @package Zeus\Kernel\ProcessManager
 * @internal
 */
abstract class AbstractProcess implements ProcessInterface
{
    /** @var ProcessState */
    protected $status;

    /** @var string */
    protected $processId;

    /** @var EventManagerInterface */
    protected $events;

    /** @var LoggerInterface */
    protected $logger;

    /** @var ConfigInterface */
    protected $config;

    /** @var IpcAdapterInterface */
    protected $ipc;

    /**
     * @param string $processId
     * @return $this
     */
    public function setProcessId($processId)
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
     * @return \Zend\Log\LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @return int
     */
    public function getProcessId()
    {
        return $this->processId;
    }

    /**
     * @return ProcessState
     */
    public function getStatus()
    {
        if (!$this->status) {
            $this->status = new ProcessState($this->getConfig()->getServiceName());
            $this->status->setProcessId($this->getProcessId());
        }

        return $this->status;
    }

    /**
     * @return Config
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
     * @param mixed $startParameters
     * @return $this
     */
    public function start($startParameters = null)
    {
        $event = new SchedulerEvent();
        $process = clone $this;

        if (!$process->getIpc()->isConnected()) {
            $process->getIpc()->connect();
        }

        $event->setTarget($process);
        $event->setName(SchedulerEvent::EVENT_PROCESS_CREATE);
        if (is_array($startParameters)) {
            $event->setParams($startParameters);
        }
        $this->getEventManager()->triggerEvent($event);
        if (!$event->getParam('init_process')) {
            return $this;
        }

        $pid = $event->getParam('uid');
        $process->setProcessId($pid);

        $event = new ProcessEvent();
        $event->setTarget($process);
        $event->setName(ProcessEvent::EVENT_PROCESS_INIT);
        $event->setParam('uid', $pid);
        $this->getEventManager()->triggerEvent($event);

        return $this;
    }

    /**
     * @param EventManagerInterface $eventManager
     * @return $this
     */
    public abstract function attach(EventManagerInterface $eventManager);

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function setEventManager(EventManagerInterface $events)
    {
        $events->setIdentifiers(array(
            __CLASS__,
            get_called_class(),
        ));
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
     * @return IpcAdapterInterface
     */
    public function getIpc()
    {
        return $this->ipc;
    }

    /**
     * @param $ipcAdapter
     * @return $this
     */
    public function setIpc($ipcAdapter)
    {
        $this->ipc = $ipcAdapter;

        return $this;
    }
}