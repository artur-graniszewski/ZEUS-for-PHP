<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use LogicException;
use RuntimeException;
use Throwable;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;
use Zend\EventManager\EventsCapableInterface;
use Zend\Log\LoggerInterface;
use Zeus\IO\Stream\SelectionKey;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Exception\SocketTimeoutException;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;

use function is_callable;
use function sleep;
use function time;
use function count;
use function array_search;
use function stream_socket_client;
use function in_array;

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

    /** @var Selector */
    private $parentIpcSelector;

    public function __construct(MultiProcessingModuleInterface $driver)
    {
        $errorMessage = '';
        if (!$driver::isSupported($errorMessage)) {
            throw new RuntimeException($errorMessage);
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
            throw new LogicException("Scheduler event not set");
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
            throw new LogicException("Worker event not set");
        }

        $workerEvent = clone $this->workerEvent;
        $workerEvent->setParams([]);
        $workerEvent->getWorker()->setTerminating(false);

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
            throw new LogicException("Logger is not set");
        }

        return $this->logger;
    }

    public function attachDefaultListeners()
    {
        $eventManager = $this->getEventManager();

        $eventManager->attach(WorkerEvent::EVENT_INIT, function (WorkerEvent $event) use ($eventManager) {
            $this->driver->onWorkerInit($event);
            $this->connectToPipe($event);
            $this->checkPipe();
            $eventManager->attach(WorkerEvent::EVENT_EXIT, function (WorkerEvent $event) {
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
        $eventManager->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_STOP, function (SchedulerEvent $e) {
            try {
                foreach ($this->ipcServers as $server) {
                    $server->getSocket()->shutdown(STREAM_SHUT_RD);
                    $server->getSocket()->close();
                }

                if ($this->ipc && !$this->ipc->isClosed()) {
                    $this->ipc->close();
                }
            } catch (Throwable $ex) {

            }
            $this->driver->onKernelStop($e);
            $this->driver->onWorkersCheck($e);
        });
        $eventManager->attach(SchedulerEvent::EVENT_START, function (SchedulerEvent $e) {
            $this->driver->onSchedulerInit($e);
        }, -9000);
        $eventManager->attach(SchedulerEvent::EVENT_STOP, function (SchedulerEvent $e) {
            $this->driver->onSchedulerStop($e);
            $this->setIsTerminating(true);

            while ($this->ipcConnections) {
                $this->registerWorkers();
                $this->driver->onWorkersCheck($e);
                if ($this->ipcConnections) {
                    sleep(1);
                    $amount = count($this->ipcConnections);
                    $this->getLogger()->info("Waiting for $amount workers to exit");
                }
            }
        }, SchedulerEvent::PRIORITY_FINALIZE);
        $eventManager->attach(WorkerEvent::EVENT_LOOP, function (WorkerEvent $event) {
            $this->driver->onWorkerLoop($event);

            $this->checkPipe();
            if ($this->isTerminating()) {
                $event->getWorker()->setTerminating(true);
                $event->stopPropagation(true);
            }
        }, WorkerEvent::PRIORITY_INITIALIZE);
        $eventManager->attach(SchedulerEvent::EVENT_LOOP, function (SchedulerEvent $event) {
            $this->driver->onSchedulerLoop($event);
            $wasExiting = $this->isTerminating();

            $this->checkPipe();
            $this->registerWorkers();
            $this->driver->onWorkersCheck($event);

            if ($this->isTerminating() && !$wasExiting) {

                $event->getScheduler()->setTerminating(true);
                $event->stopPropagation(true);
            }
        }, -9000);
        $eventManager->attach(WorkerEvent::EVENT_CREATE, function (WorkerEvent $event) {
            $pipe = $this->createPipe();
            $event->setParam(static::ZEUS_IPC_ADDRESS_PARAM, $pipe->getLocalAddress());
            $this->driver->onWorkerCreate($event);
            if (!$event->getParam('initWorker', false)) {
                $this->registerWorker($event->getWorker()->getUid(), $pipe);
            }
        }, WorkerEvent::PRIORITY_FINALIZE + 1);
        $eventManager->attach(WorkerEvent::EVENT_TERMINATE, function (WorkerEvent $e) {
            $this->driver->onWorkerTerminate($e);
            $this->unregisterWorker($e->getParam('uid'));
        }, -9000);
        $eventManager->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_LOOP, function (SchedulerEvent $event) {
            $this->registerWorkers();
            $this->driver->onWorkersCheck($event);

            $this->driver->onKernelLoop($event);
        }, -9000);
        $eventManager->attach(WorkerEvent::EVENT_TERMINATED, function (WorkerEvent $e) {
            $this->driver->onWorkerTerminated($e);
        }, WorkerEvent::PRIORITY_FINALIZE);

        if (is_callable([$this->driver, 'attach'])) {
            $this->driver->attach($eventManager);
        }
    }

    public function raiseWorkerExitedEvent(int $uid, int $processId, int $threadId)
    {
        $event = $this->getWorkerEvent();
        $event->setName(WorkerEvent::EVENT_TERMINATED);
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
            foreach ($this->ipcSelector->getSelectionKeys() as $key) {
                /** @var SocketStream $stream */
                $stream = $key->getStream();
                try {
                    $stream->read();
                } catch (Throwable $ex) {
                    $uid = array_search($stream, $this->ipcConnections);
                    if ($uid) {
                        $this->unregisterWorker($uid);
                    }
                }
            }
        }

        if ($this->isTerminating()) {
            return $this;
        }

        foreach ($this->ipcServers as $uid => $server) {
            try {
                $connection = $this->ipcServers[$uid]->accept();
                $this->setStreamOptions($connection);
                $this->ipcConnections[$uid] = $connection;
                $this->ipcSelector->register($connection, SelectionKey::OP_READ);
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
            $this->parentIpcSelector = new Selector();
            $this->ipc->register($this->parentIpcSelector, SelectionKey::OP_READ);
            $this->setStreamOptions($this->ipc);
        }
    }

    private function setStreamOptions(SocketStream $stream)
    {
        try {
            $stream->setOption(SO_KEEPALIVE, 1);
            $stream->setOption(TCP_NODELAY, 1);
        } catch (UnsupportedOperationException $e) {
            // this may happen in case of disabled PHP extension, or definitely happen in case of HHVM
        }
    }

    private function checkPipe()
    {
        static $lastCheck;

        if ($this->isTerminating()) {
            return;
        }

        try {
            $now = time();
            if ($lastCheck === $now) {
                return;
            }

            $lastCheck = $now;
            if ($this->ipc->isClosed() || ($this->parentIpcSelector->select(0) === 1 && in_array($this->ipc->read(), ['@', ''])) || (!$this->ipc->write("!") && !$this->ipc->flush())) {
                $this->setIsTerminating(true);
                return;
            }
        } catch (Throwable $exception) {
            $this->setIsTerminating(true);
        }

    }

    private function unregisterWorker(int $uid)
    {
        if (isset($this->ipcConnections[$uid])) {
            $connection = $this->ipcConnections[$uid];
            unset($this->ipcConnections[$uid]);
            $this->ipcSelector->unregister($connection);
            try {
                $connection->write('@');
                $connection->flush();
                $connection->shutdown(STREAM_SHUT_RD);
            } catch (IOException $ex) {

            }
            $connection->close();
        }
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