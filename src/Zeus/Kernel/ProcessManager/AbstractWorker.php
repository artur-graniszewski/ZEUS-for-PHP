<?php

namespace Zeus\Kernel\ProcessManager;

use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\IpcServer\SocketStream;
use Zeus\Kernel\ProcessManager\Status\WorkerState;

/**
 * Class AbstractWorker
 * @package Zeus\Kernel\ProcessManager
 * @internal
 */
abstract class AbstractWorker implements ProcessInterface, ThreadInterface, WorkerInterface
{
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

    /** @var IpcAdapterInterface */
    protected $ipc;

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
     * @return \Zend\Log\LoggerInterface
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
        $event->setName(SchedulerEvent::EVENT_WORKER_CREATE);
        if (is_array($startParameters)) {
            $event->setParams($startParameters);
        }
        $this->getEventManager()->triggerEvent($event);
        if (!$event->getParam('init_process')) {
            return $this;
        }

        $params = $event->getParams();

        $pid = $event->getParam('uid');
        $process->setProcessId($pid);
        $process->setThreadId($event->getParam('threadId', 1));

        $event = new WorkerEvent();
        $event->setTarget($process);
        $event->setName(WorkerEvent::EVENT_WORKER_INIT);
        $event->setParams($params);
        $event->setParam('uid', $pid);
        $event->setParam('processId', $pid);
        $event->setParam('threadId', $event->getParam('threadId', 1));
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
     * @param IpcAdapterInterface $ipcAdapter
     * @return $this
     */
    public function setIpc(IpcAdapterInterface $ipcAdapter)
    {
        $this->ipc = $ipcAdapter;

        return $this;
    }


    /**
     * @param int $channel
     * @param string $type
     * @param mixed|mixed[] $message
     * @return $this
     * @todo: move this to an AbstractProcess or a Plugin?
     */
    public function sendMessage(int $channel, string $type, $message)
    {
        $payload = [
            'type' => $type,
            'message' => $message,
            'extra' => [
                'uid' => $this->getProcessId(),
                'threadId' => $this->getThreadId(),
                'processId' => $this->getProcessId(),
                'logger' => __CLASS__,
            ]
        ];

        $this->getIpc()->send($channel, $payload);

        return $this;
    }

    /**
     * @param $ipcAdapter
     * @return $this
     */
    public function setNewIpc($ipcAdapter)
    {
        $this->ipcAdapter = $ipcAdapter;

        return $this;
    }

    /**
     * @return SocketStream mixed
     */
    public function getNewIpc()
    {
        return $this->ipcAdapter;
    }
}