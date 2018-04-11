<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use LogicException;
use RuntimeException;
use Throwable;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;
use Zend\EventManager\EventsCapableInterface;
use Zend\Log\LoggerAwareTrait;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\IO\Stream\SelectionKey;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Exception\SocketTimeoutException;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;
use Zeus\Kernel\System\Runtime;

use function is_callable;
use function sleep;
use function time;
use function count;
use function array_search;
use function stream_socket_client;
use function in_array;

class ModuleWrapper implements EventsCapableInterface, EventManagerAwareInterface
{
    use EventManagerAwareTrait;
    use LoggerAwareTrait;

    const LOOPBACK_INTERFACE = 'tcp://127.0.0.1';
    const UPSTREAM_CONNECTION_TIMEOUT = 5;
    const ZEUS_IPC_ADDRESS_PARAM = 'zeusIpcAddress';

    /** @var int */
    private $ipcAddress;

    /** @var MultiProcessingModuleInterface */
    private $driver;

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
        //$workerEvent->getWorker()->setTerminating(false);

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

    public function setTerminating(bool $isTerminating)
    {
        $this->isTerminating = $isTerminating;
    }

    protected function attachDefaultListeners()
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
                    Runtime::exit($status);
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
                    $socket = $server->getSocket();
                    $socket->shutdown(STREAM_SHUT_RD);
                    $socket->close();
                }

                $ipc = $this->ipc;
                if ($ipc && !$ipc->isClosed()) {
                    $ipc->close();
                }
            } catch (Throwable $ex) {

            }
            $this->driver->onKernelStop($e);
            $this->driver->onWorkersCheck($e);
        });

        $eventManager->attach(SchedulerEvent::EVENT_START, function (SchedulerEvent $e) {
            $this->logCapabilities();
        }, SchedulerEvent::PRIORITY_INITIALIZE);

        $eventManager->attach(SchedulerEvent::EVENT_START, function (SchedulerEvent $e) {
            $this->driver->onSchedulerInit($e);
        }, -9000);
        $eventManager->attach(SchedulerEvent::EVENT_STOP, function (SchedulerEvent $e) {
            $this->driver->onSchedulerStop($e);
            $this->setTerminating(true);

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
                $event->getWorker()->setCode(WorkerState::EXITING);
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

    private function logCapabilities()
    {
        $driver = $this->driver;
        $capabilities = $driver::getCapabilities();
        $driverName = get_class($driver);

        $logger = $this->getLogger();
        $logger->notice(sprintf("Using %s MPM module", substr($driverName, strrpos($driverName, '\\')+1)));
        $logger->info("Enumerating module capabilities:");
        $logger->info(sprintf("* Using %s isolation level", $capabilities->getIsolationLevelName()));
        $logger->info(sprintf("* Using %s signal handler", $capabilities->isAsyncSignalHandler() ? 'asynchronous': 'synchronous'));
        $logger->info(sprintf("* Parent memory pages are %s", $capabilities->isCopyingParentMemoryPages() ? 'copied': 'not copied'));
    }

    public function raiseWorkerExitedEvent(int $uid, int $processId, int $threadId)
    {
        $event = $this->getWorkerEvent();
        $event->setName(WorkerEvent::EVENT_TERMINATED);
        $worker = $event->getWorker();
        $worker->setUid($uid);
        $worker->setProcessId($processId);
        $worker->setThreadId($threadId);
        $worker->setCode(WorkerState::TERMINATED);
        $this->getEventManager()->triggerEvent($event);
        $this->unregisterWorker($uid);
    }

    private function registerWorkers()
    {
        $ipcSelector = $this->ipcSelector;

        // read all keep-alive messages
        if ($ipcSelector->select(0)) {
            foreach ($ipcSelector->getSelectionKeys() as $key) {
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
            return;
        }

        foreach ($this->ipcServers as $uid => $server) {
            try {
                $connection = $server->accept();
                $this->setStreamOptions($connection);
                $this->ipcConnections[$uid] = $connection;
                $ipcSelector->register($connection, SelectionKey::OP_READ);
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
            $this->setTerminating(true);

            return;
        }

        $ipc = new SocketStream($stream);
        $ipc->setBlocking(false);
        $this->setStreamOptions($ipc);
        $this->parentIpcSelector = new Selector();
        $ipc->register($this->parentIpcSelector, SelectionKey::OP_READ);
        $this->ipc = $ipc;
    }

    private function setStreamOptions(NetworkStreamInterface $stream)
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

            $ipc = $this->ipc;

            $lastCheck = $now;
            if ($ipc->isClosed() || ($this->parentIpcSelector->select(0) === 1 && in_array($ipc->read(), ['@', ''])) || (!$ipc->write("!") && !$ipc->flush())) {
                $this->setTerminating(true);

                return;
            }
        } catch (Throwable $exception) {
            $this->setTerminating(true);
        }

    }

    private function unregisterWorker(int $uid)
    {
        if (!isset($this->ipcConnections[$uid])) {
            return;
        }

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

    private function registerWorker(int $uid, SocketServer $pipe)
    {
        $this->ipcServers[$uid] = $pipe;
    }

    /**
     * @todo Make it private!!!! Now its needed only by DummyMPM test module
     * @internal
     */
    public function createPipe() : SocketServer
    {
        $socketServer = new SocketServer(0, 500, static::LOOPBACK_INTERFACE);
        $socketServer->setSoTimeout(10);

        return $socketServer;
    }
}