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
    /** @var EventManagerInterface */
    private $events;

    /** @var int */
    private $ipcAddress;

    /** @var SchedulerEvent */
    private $schedulerEvent;

    /** @var WorkerEvent */
    private $workerEvent;

    /** @var bool */
    private $isTerminating = false;

    /** @var SocketServer[] */
    private $ipcServers = [];

    /** @var SocketStream[] */
    private $ipcConnections = [];

    /** @var SocketStream */
    private $ipc;

    /** @var Selector */
    private $ipcSelector;

    /** @var LoggerInterface */
    private $logger;

    public function __construct()
    {
        $errorMessage = '';
        if (!static::isSupported($errorMessage)) {
            throw new \RuntimeException($errorMessage);
        }

        $this->ipcSelector = new Selector();
    }

    /**
     * @param SchedulerEvent $event
     */
    public function setSchedulerEvent(SchedulerEvent $event)
    {
        $this->schedulerEvent = $event;
    }

    public function getSchedulerEvent(): SchedulerEvent
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

    public function getWorkerEvent(): WorkerEvent
    {
        if (!$this->workerEvent) {
            throw new \LogicException("Worker event not set");
        }

        $workerEvent = clone $this->workerEvent;
        $workerEvent->setParams([]);
        $workerEvent->getWorker()->setIsTerminating(false);

        return $workerEvent;
    }

    public function getIpcAddress(): string
    {
        return $this->ipcAddress;
    }

    public function setIpcAddress(string $address)
    {
        $this->ipcAddress = $address;
    }

    public function isTerminating(): bool
    {
        return $this->isTerminating;
    }

    public function setIsTerminating(bool $isTerminating)
    {
        $this->isTerminating = $isTerminating;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getLogger(): LoggerInterface
    {
        if (!isset($this->logger)) {
            throw new \LogicException("Logger is not set");
        }

        return $this->logger;
    }

    public function attach(EventManagerInterface $eventManager)
    {
        $this->events = $eventManager;
        $this->events->attach(WorkerEvent::EVENT_WORKER_INIT, function (WorkerEvent $event) use ($eventManager) {
            $this->onWorkerInit($event);
            $this->connectToPipe($event);
            $this->checkPipe();
            $eventManager->attach(WorkerEvent::EVENT_WORKER_EXIT, function (WorkerEvent $event) {
                $this->onWorkerExit($event);

                if (!$event->propagationIsStopped()) {
                    /** @var \Exception $exception */
                    $exception = $event->getParam('exception');

                    $status = $exception ? $exception->getCode() : 0;
                    exit($status);
                }
            }, WorkerEvent::PRIORITY_FINALIZE);
        }, WorkerEvent::PRIORITY_INITIALIZE + 1);

        $eventManager->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, function (SchedulerEvent $e) {
            $this->onKernelStart($e);
            $this->onWorkersCheck($e);
        });
        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_START, function (SchedulerEvent $e) {
            $this->onSchedulerInit($e);
        }, -9000);
        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, function (SchedulerEvent $e) {
            $this->onSchedulerStop($e);
            $this->setIsTerminating(true);

            while ($this->ipcConnections) {
                $this->registerWorkers();
                $this->onWorkersCheck($e);
                if ($this->ipcConnections) {
                    sleep(1);
                    $amount = count($this->ipcConnections);
                    $this->getLogger()->info("Waiting $amount for workers to exit");
                }
            }
        }, SchedulerEvent::PRIORITY_FINALIZE);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_LOOP, function (WorkerEvent $event) {
            $this->onWorkerLoop($event);
            $this->checkPipe();

            if ($this->isTerminating()) {
                $event->getWorker()->setIsTerminating(true);
                $event->stopPropagation(true);
            }
        }, WorkerEvent::PRIORITY_INITIALIZE);
        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, function (SchedulerEvent $event) {
            $this->onSchedulerLoop($event);
            $wasExiting = $this->isTerminating();

            $this->checkPipe();
            $this->registerWorkers();
            $this->onWorkersCheck($event);

            if ($this->isTerminating() && !$wasExiting) {
                $event->getScheduler()->setIsTerminating(true);
                $event->stopPropagation(true);
            }
        }, -9000);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_CREATE, function (WorkerEvent $event) {
            $pipe = $this->createPipe();
            $event->setParam(static::ZEUS_IPC_ADDRESS_PARAM, $pipe->getLocalAddress());
            $this->onWorkerCreate($event);
            if (!$event->getParam('init_process', false)) {
                $this->registerWorker($event->getWorker()->getUid(), $pipe);
            }

        }, WorkerEvent::PRIORITY_FINALIZE + 1);
        $eventManager->attach(SchedulerEvent::EVENT_WORKER_TERMINATE, function (SchedulerEvent $e) {
            $this->onWorkerTerminate($e);
            $this->unregisterWorker($e->getParam('uid'));
        }, -9000);
        $eventManager->attach(SchedulerEvent::EVENT_KERNEL_LOOP, function (SchedulerEvent $e) {
            $this->onKernelLoop($e);
        }, -9000);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_TERMINATED, function (WorkerEvent $e) {
            $this->onWorkerTerminated($e);
        }, WorkerEvent::PRIORITY_FINALIZE);
    }

    public function onKernelStart(SchedulerEvent $event)
    {
    }

    public function onKernelLoop(SchedulerEvent $event)
    {
    }

    public function onWorkerCreate(WorkerEvent $event)
    {
    }

    public function onSchedulerStop(SchedulerEvent $event)
    {
    }

    public function onWorkerTerminate(SchedulerEvent $event)
    {
    }

    public function onWorkerExit(WorkerEvent $event)
    {
    }

    public function onSchedulerInit(SchedulerEvent $event)
    {
    }

    public function onWorkerInit(WorkerEvent $event)
    {
    }

    public function onWorkerTerminated(WorkerEvent $event)
    {
    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
    }

    public function onWorkersCheck(SchedulerEvent $event)
    {
    }

    protected function raiseWorkerExitedEvent($uid, $processId, $threadId)
    {
        $event = $this->getWorkerEvent();
        $event->setName(WorkerEvent::EVENT_WORKER_TERMINATED);
        $event->getWorker()->setUid($uid);
        $event->getWorker()->setProcessId($processId);
        $event->getWorker()->setThreadId($threadId);
        $this->events->triggerEvent($event);
        $this->unregisterWorker($uid);
    }

    private function registerWorkers()
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
    }

    private function connectToPipe(WorkerEvent $event)
    {
        $stream = @stream_socket_client($this->getIpcAddress(), $errno, $errstr, static::UPSTREAM_CONNECTION_TIMEOUT);

        if (!$stream) {
            $this->getLogger()->err("Upstream pipe unavailable on port: " . $this->getIpcAddress());
            $this->setIsTerminating(true);
        } else {
            $this->ipc = new SocketStream($stream);
            $this->ipc->setBlocking(false);
            $this->ipc->setOption(SO_KEEPALIVE, 1);
            $this->ipc->setOption(TCP_NODELAY, 1);
        }
    }

    /**
     * @return $this
     */
    private function checkPipe()
    {
        if (!$this->isTerminating()) {
            try {
                $this->ipc->select(0);
                $this->ipc->write("!")->flush();
            } catch (\Throwable $exception) {
                $this->setIsTerminating(true);
            }
        }

        return $this;
    }

    private function unregisterWorker(int $uid)
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
    private function registerWorker(int $uid, SocketServer $pipe)
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
}