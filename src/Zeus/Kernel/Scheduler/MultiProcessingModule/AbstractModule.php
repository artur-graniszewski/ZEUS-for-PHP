<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\SocketServer;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;

abstract class AbstractModule implements MultiProcessingModuleInterface
{
    const LOOPBACK_INTERFACE = '127.0.0.1';

    const UPSTREAM_CONNECTION_TIMEOUT = 5;

    /** @var EventManagerInterface */
    protected $events;

    /** @var int */
    protected $connectionPort;

    /** @var SchedulerEvent */
    protected $schedulerEvent;

    /** @var WorkerEvent */
    protected $workerEvent;

    /** @var bool */
    private $isTerminating = false;

    /** @var SocketServer[] */
    protected $ipcServers = [];

    /** @var SocketStream[] */
    protected $ipcConnections = [];

    /** @var SocketStream */
    protected $ipc;

    /** @var Selector */
    protected $ipcSelector;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param SchedulerEvent $event
     */
    public function setSchedulerEvent(SchedulerEvent $event)
    {
        $this->schedulerEvent = $event;
    }

    public function getSchedulerEvent() : SchedulerEvent
    {
        if (!$this->schedulerEvent) {
            throw new \LogicException("Scheduler event not set");
        }

        return clone $this->schedulerEvent;
    }

    /**
     * @param WorkerEvent $event
     */
    public function setWorkerEvent(WorkerEvent $event)
    {
        $this->workerEvent = $event;
    }

    public function getWorkerEvent() : WorkerEvent
    {
        if (!$this->workerEvent) {
            throw new \LogicException("Worker event not set");
        }

        $workerEvent = clone $this->workerEvent;
        $workerEvent->setParams([]);
        $workerEvent->getWorker()->setIsTerminating(false);
        return $workerEvent;
    }

    public function __construct()
    {
        $this->ipcSelector = new Selector();
    }

    public function attach(EventManagerInterface $eventManager)
    {
        $this->events = $eventManager;
        $this->events->attach(WorkerEvent::EVENT_WORKER_CREATE, function (WorkerEvent $e) {
            $worker = clone $e->getWorker();
            $worker->setIsTerminating(false);
            $e->setWorker($worker);
            $e->setTarget($worker);
            $worker->attach($this->events);
        }, WorkerEvent::PRIORITY_INITIALIZE + 10);

        $this->events->attach(WorkerEvent::EVENT_WORKER_INIT, function (WorkerEvent $e) use ($eventManager) {
            $eventManager->attach(WorkerEvent::EVENT_WORKER_EXIT, function (WorkerEvent $e) {
                $this->onExit($e);
            }, SchedulerEvent::PRIORITY_FINALIZE);
        }, WorkerEvent::PRIORITY_INITIALIZE);
    }

    /**
     * @param WorkerEvent $event
     */
    private function onExit(WorkerEvent $event)
    {
        /** @var \Exception $exception */
        $exception = $event->getParam('exception');

        $status = $exception ? $exception->getCode(): 0;
        exit($status);
    }

    public function isTerminating()
    {
        return $this->isTerminating;
    }

    protected function checkWorkers()
    {
        // read all keep-alive messages
        if ($this->ipcSelector->select(0)) {
            foreach ($this->ipcSelector->getSelectedStreams() as $stream) {
                try {
                    $stream->read();
                } catch (\Exception $ex) {

                }
            }
        }

        if ($this->isTerminating()) {
            return $this;
        }

        foreach ($this->ipcServers as $uid => $server) {
            try {
                $connection = $this->ipcServers[$uid]->accept();
                $this->ipcConnections[$uid] = $connection;
                $this->ipcSelector->register($connection, Selector::OP_READ);
                $this->ipcServers[$uid]->close();
                unset($this->ipcServers[$uid]);
            } catch (SocketTimeoutException $exception) {
                // @todo: verify if nothing to do?
            }
        }

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
     * @return LoggerInterface
     */
    public function getLogger() : LoggerInterface
    {
        if (!isset($this->logger)) {
            throw new \LogicException("Logger not available");
        }
        return $this->logger;
    }

    /**
     * @return $this
     */
    protected function checkPipe()
    {
        if (!$this->isTerminating) {
            if (!isset($this->ipc)) {
                $stream = @stream_socket_client(sprintf('tcp://%s:%d', static::LOOPBACK_INTERFACE, $this->getConnectionPort()), $errno, $errstr, static::UPSTREAM_CONNECTION_TIMEOUT);

                if (!$stream) {
                    $this->getLogger()->err("Upstream pipe unavailable on port: " . $this->getConnectionPort());
                    $this->isTerminating = true;
                } else {
                    $this->ipc = new SocketStream($stream);
                    $this->ipc->setBlocking(false);
                }
            } else {
                try {
                    $this->ipc->select(0);
                    $this->ipc->write("!")->flush();
                } catch (\Exception $exception) {
                    $this->isTerminating = true;
                }
            }
        }

        return $this;
    }

    protected function onWorkerLoop(WorkerEvent $event)
    {
        $this->checkPipe();

        if ($this->isTerminating()) {
            $event->getWorker()->setIsTerminating(true);
            $event->stopPropagation(true);
        }
    }

    protected function raiseWorkerExitedEvent($uid, $processId, $threadId)
    {
        $newEvent = $this->getSchedulerEvent();
        $newEvent->setParam('uid', $uid);
        $newEvent->setParam('threadId', $threadId);
        $newEvent->setParam('processId', $processId);
        $newEvent->setName(SchedulerEvent::EVENT_WORKER_TERMINATED);
        $this->events->triggerEvent($newEvent);
    }

    /**
     * @param int $uid
     * @param bool $useSoftTermination
     * @return $this
     */
    public function onStopWorker(int $uid, bool $useSoftTermination)
    {
        if (!isset($this->ipcConnections[$uid])) {
            $this->getLogger()->warn("Trying to stop already detached worker $uid");
            return $this;
        }

        $this->unregisterWorker($uid);

        return $this;
    }

    public function stopWorker(int $uid, bool $isSoftStop)
    {
        $newEvent = $this->getSchedulerEvent();
        $newEvent->setParam('uid', $uid);
        $newEvent->setParam('soft', $isSoftStop);
        $newEvent->setName(SchedulerEvent::EVENT_WORKER_TERMINATE);

        $this->events->triggerEvent($newEvent);
    }

    protected function unregisterWorker(int $uid)
    {
        if (isset($this->ipcConnections[$uid])) {
            $this->ipcConnections[$uid]->close();
            $this->ipcSelector->unregister($this->ipcConnections[$uid]);
            unset($this->ipcConnections[$uid]);
        }

        return $this;
    }

    /**
     * @param int $uid
     * @param SocketServer $pipe
     * @return $this
     */
    protected function registerWorker(int $uid, SocketServer $pipe)
    {
        $this->ipcServers[$uid] = $pipe;

        return $this;
    }

    /**
     * @return SocketServer
     */
    protected function createPipe()
    {
        $socketServer = new SocketServer(0, 500, static::LOOPBACK_INTERFACE);
        $socketServer->setSoTimeout(10);

        return $socketServer;
    }

    protected function onSchedulerStop()
    {
        $this->isTerminating = true;
    }

    /**
     * @return int
     */
    protected function getConnectionPort() : int
    {
        return $this->connectionPort;
    }

    protected function setConnectionPort(int $port)
    {
        $this->connectionPort = $port;

        return $this;
    }

    public function startWorker($startParameters = null)
    {
        $event = $this->getWorkerEvent();
        $event->setName(WorkerEvent::EVENT_WORKER_CREATE);

        if (is_array($startParameters)) {
            $event->setParams($startParameters);
        }
        $this->events->triggerEvent($event);
        if (!$event->getParam('init_process')) {
            return $this;
        }

        $params = $event->getParams();

        $pid = $event->getParam('uid');
        $event = $this->getWorkerEvent();
        // @fixme: why worker UID must be set after getWorkerEvent and not before? it shouldnt be cloned
        $worker = $event->getWorker();
        $worker->setProcessId($pid);

        $worker->setThreadId($event->getParam('threadId', 1));

        $event->setName(WorkerEvent::EVENT_WORKER_INIT);
        $event->setParams($params);
        $event->setParam('uid', $pid);
        $event->setParam('processId', $pid);
        $event->setParam('threadId', $event->getParam('threadId', 1));
        $event->setTarget($worker);
        $this->events->triggerEvent($event);
    }
}