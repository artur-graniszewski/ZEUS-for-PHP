<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;
use Zend\EventManager\EventsCapableInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\SocketServer;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;

class ModuleWrapper implements EventsCapableInterface, EventManagerAwareInterface
{
    const LOOPBACK_INTERFACE = '127.0.0.1';

    const UPSTREAM_CONNECTION_TIMEOUT = 5;

    const ZEUS_IPC_ADDRESS_PARAM = 'zeusIpcAddress';

    use EventManagerAwareTrait;

    /** @var int */
    private $ipcAddress;

    /** @var MultiProcessingModuleInterface */
    private $driver;

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

    public function __construct(MultiProcessingModuleInterface $driver)
    {
        $errorMessage = '';
        if (!$driver::isSupported($errorMessage)) {
            throw new \RuntimeException($errorMessage);
        }

        $this->driver = $driver;
        $this->driver->setWrapper($this);

        $this->ipcSelector = new Selector();
    }

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

    public function attachDefaultListeners()
    {
        $eventManager = $this->getEventManager();

        $eventManager->attach(WorkerEvent::EVENT_WORKER_INIT, function (WorkerEvent $event) use ($eventManager) {
            $this->driver->onWorkerInit($event);
            $this->connectToPipe($event);
            $this->checkPipe();
            $eventManager->attach(WorkerEvent::EVENT_WORKER_EXIT, function (WorkerEvent $event) {
                $this->driver->onWorkerExit($event);

                if (!$event->propagationIsStopped()) {
                    /** @var \Exception $exception */
                    $exception = $event->getParam('exception');

                    $status = $exception ? $exception->getCode() : 0;
                    exit($status);
                }
            }, WorkerEvent::PRIORITY_FINALIZE);
        }, WorkerEvent::PRIORITY_INITIALIZE + 1);

        $eventManager->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, function (SchedulerEvent $e) {
            $this->driver->onKernelStart($e);
            $this->driver->onWorkersCheck($e);
        });
        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_START, function (SchedulerEvent $e) {
            $this->driver->onSchedulerInit($e);
        }, -9000);
        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, function (SchedulerEvent $e) {
            $this->driver->onSchedulerStop($e);
            $this->setIsTerminating(true);

            while ($this->ipcConnections) {
                $this->registerWorkers();
                $this->driver->onWorkersCheck($e);
                if ($this->ipcConnections) {
                    sleep(1);
                    $amount = count($this->ipcConnections);
                    $this->getLogger()->info("Waiting $amount for workers to exit");
                }
            }
        }, SchedulerEvent::PRIORITY_FINALIZE);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_LOOP, function (WorkerEvent $event) {
            $this->driver->onWorkerLoop($event);
            $this->checkPipe();

            if ($this->isTerminating()) {
                $event->getWorker()->setIsTerminating(true);
                $event->stopPropagation(true);
            }
        }, WorkerEvent::PRIORITY_INITIALIZE);
        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, function (SchedulerEvent $event) {
            $this->driver->onSchedulerLoop($event);
            $wasExiting = $this->isTerminating();

            $this->checkPipe();
            $this->registerWorkers();
            $this->driver->onWorkersCheck($event);

            if ($this->isTerminating() && !$wasExiting) {
                $event->getScheduler()->setIsTerminating(true);
                $event->stopPropagation(true);
            }
        }, -9000);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_CREATE, function (WorkerEvent $event) {
            $pipe = $this->createPipe();
            $event->setParam(static::ZEUS_IPC_ADDRESS_PARAM, $pipe->getLocalAddress());
            $this->driver->onWorkerCreate($event);
            if (!$event->getParam('initWorker', false)) {
                $this->registerWorker($event->getWorker()->getUid(), $pipe);
            }
        }, WorkerEvent::PRIORITY_FINALIZE + 1);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_TERMINATE, function (WorkerEvent $e) {
            $this->driver->onWorkerTerminate($e);
            $this->unregisterWorker($e->getParam('uid'));
        }, -9000);
        $eventManager->attach(SchedulerEvent::EVENT_KERNEL_LOOP, function (SchedulerEvent $e) {
            $this->driver->onKernelLoop($e);
        }, -9000);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_TERMINATED, function (WorkerEvent $e) {
            $this->driver->onWorkerTerminated($e);
        }, WorkerEvent::PRIORITY_FINALIZE);

        if (is_callable([$this->driver, 'attach'])) {
            $this->driver->attach($eventManager);
        }
    }

    public function raiseWorkerExitedEvent(int $uid, int $processId, int $threadId)
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

    private function checkPipe()
    {
        if (!$this->isTerminating()) {
            try {
                $this->ipc->select(0);
                $this->ipc->write("!");
                $this->ipc->flush();
            } catch (\Throwable $exception) {
                //$this->getLogger()->err((string) $exception); die();
                $this->setIsTerminating(true);
            }
        }
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

    private function registerWorker(int $uid, SocketServer $pipe)
    {
        $this->ipcServers[$uid] = $pipe;
    }

    /**
     * @todo Make it private!!!! Now its needed only by DummyMPM test module
     */
    public function createPipe() : SocketServer
    {
        $socketServer = new SocketServer(0, 500, static::LOOPBACK_INTERFACE);
        $socketServer->setSoTimeout(10);

        return $socketServer;
    }
}