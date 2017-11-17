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
    private $events;

    /** @var int */
    private $connectionPort;

    /** @var SchedulerEvent */
    private $schedulerEvent;

    /** @var WorkerEvent */
    protected $workerEvent;

    /** @var bool */
    private $isTerminating = false;

    /** @var SocketServer[] */
    private $ipcServers = [];

    /** @var SocketStream[] */
    private $ipcConnections = [];

    /** @var SocketStream */
    protected $ipc;

    /** @var Selector */
    private $ipcSelector;

    /** @var LoggerInterface */
    private $logger;

    public function __construct()
    {
        $this->ipcSelector = new Selector();
    }

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

    public function attach(EventManagerInterface $eventManager)
    {
        $this->events = $eventManager;
        $this->events->attach(WorkerEvent::EVENT_WORKER_INIT, function (WorkerEvent $event) use ($eventManager) {
            $this->connectToPipe($event);
            $eventManager->attach(WorkerEvent::EVENT_WORKER_EXIT, function (WorkerEvent $e) {
                $this->onWorkerExit($e);
            }, SchedulerEvent::PRIORITY_FINALIZE);
        }, WorkerEvent::PRIORITY_INITIALIZE + 1);

        $eventManager->attach(WorkerEvent::EVENT_WORKER_INIT, function($e) { $this->onWorkerInit($e);}, WorkerEvent::PRIORITY_INITIALIZE + 1);

        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_START, function(SchedulerEvent $e) { $this->onSchedulerInit($e); }, -9000);
        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, function(SchedulerEvent $e) { $this->onSchedulerStop($e); }, SchedulerEvent::PRIORITY_FINALIZE);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_LOOP, function (WorkerEvent $e) { $this->onWorkerLoop($e);}, WorkerEvent::PRIORITY_INITIALIZE);
        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, function(SchedulerEvent $e) { $this->onSchedulerLoop($e); }, -9000);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_CREATE, function (WorkerEvent $e) { $this->onWorkerCreate($e);}, WorkerEvent::PRIORITY_FINALIZE + 1);
        $eventManager->attach(SchedulerEvent::EVENT_WORKER_TERMINATE, function(SchedulerEvent $e) { $this->onWorkerTerminate($e); }, -9000);
        $eventManager->attach(SchedulerEvent::EVENT_KERNEL_LOOP, function(SchedulerEvent $e) {$this->onKernelLoop($e);}, -9000);
    }

    protected function connectToPipe(WorkerEvent $event)
    {
        $this->setConnectionPort($event->getParam('connectionPort'));
        $stream = @stream_socket_client(sprintf('tcp://%s:%d', static::LOOPBACK_INTERFACE, $this->getConnectionPort()), $errno, $errstr, static::UPSTREAM_CONNECTION_TIMEOUT);

        if (!$stream) {
            $this->getLogger()->err("Upstream pipe unavailable on port: " . $this->getConnectionPort());
            $this->isTerminating = true;
        } else {
            $this->ipc = new SocketStream($stream);
            $this->ipc->setBlocking(false);
            $this->ipc->setOption(SO_KEEPALIVE, 1);
            $this->ipc->setOption(TCP_NODELAY, 1);
        }
    }

    protected function onKernelLoop(SchedulerEvent $event)
    {
        $this->checkWorkers();
    }

    protected abstract function onWorkerCreate(WorkerEvent $event);


    /**
     * @param SchedulerEvent $event
     */
    protected function onWorkerTerminate(SchedulerEvent $event)
    {
        $this->unregisterWorker($event->getParam('uid'));
    }

    /**
     * @param WorkerEvent $event
     */
    protected function onWorkerExit(WorkerEvent $event)
    {
        /** @var \Exception $exception */
        $exception = $event->getParam('exception');

        $status = $exception ? $exception->getCode(): 0;
        exit($status);
    }

    public function isTerminating() : bool
    {
        return $this->isTerminating;
    }

    public function setIsTerminating(bool $isTerminating)
    {
        $this->isTerminating = $isTerminating;
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
                $connection->setOption(TCP_NODELAY, 1);
                $connection->setOption(SO_KEEPALIVE, 1);
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
        if (!$this->isTerminating()) {
            try {
                $this->ipc->select(0);
                $this->ipc->write("!")->flush();
            } catch (\Throwable $exception) {
                $this->isTerminating = true;
            }
        }

        return $this;
    }

    protected function onSchedulerLoop(SchedulerEvent $event)
    {
        $wasExiting = $this->isTerminating();

        $this->checkPipe();
        $this->checkWorkers();

        if ($this->isTerminating() && !$wasExiting) {
            $event->getScheduler()->setIsTerminating(true);
            $event->stopPropagation(true);
        }
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
        $this->unregisterWorker($uid);
        $event = $this->getWorkerEvent();
        $event->setName(WorkerEvent::EVENT_WORKER_TERMINATED);
        $event->getWorker()->setUid($uid);
        $event->getWorker()->setProcessId($processId);
        $event->getWorker()->setThreadId($threadId);
        $this->events->triggerEvent($event);
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

    protected function onSchedulerStop(SchedulerEvent $event)
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
    }

    protected function onSchedulerInit(SchedulerEvent $event)
    {

    }

    protected function onWorkerInit(WorkerEvent $event)
    {
        $this->setConnectionPort($event->getParam('connectionPort'));
    }
}