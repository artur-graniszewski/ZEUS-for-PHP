<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\Kernel\ProcessManager\WorkerEvent;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\SocketServer;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;

abstract class AbstractModule
{
    const LOOPBACK_INTERFACE = '127.0.0.1';

    const UPSTREAM_CONNECTION_TIMEOUT = 5;

    /** @var EventManagerInterface */
    protected $events;

    /** @var int */
    protected $connectionPort;

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
     * PosixDriver constructor.
     */
    public function __construct()
    {
        $this->ipcSelector = new Selector();
    }

    public function attach(EventManagerInterface $events)
    {
        $this->events = $events;
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
                if (isset($this->ipcServers[$uid])) {
                    $connection = $this->ipcServers[$uid]->accept();
                    $this->ipcConnections[$uid] = $connection;
                    $this->ipcSelector->register($connection);
                }
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
                    $this->ipc->write(" ")->flush();
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
            $event->stopWorker(true);
            $event->stopPropagation(true);
        }
    }

    protected function raiseWorkerExitedEvent($uid, $processId, $threadId)
    {
        $newEvent = new SchedulerEvent();
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
    protected function stopWorker(int $uid, bool $useSoftTermination)
    {
        if (!isset($this->ipcServers[$uid])) {
            $this->getLogger()->warn("Trying to stop already detached thread $uid");
            return $this;
        }

        $this->unregisterWorker($uid);

        return $this;
    }

    protected function unregisterWorker(int $uid)
    {
        if (isset($this->ipcServers[$uid])) {
            $this->ipcServers[$uid]->close();
            unset($this->ipcServers[$uid]);
        }

        if (isset($this->ipcConnections[$uid])) {
            $this->ipcConnections[$uid]->close();
            $this->ipcSelector->unregister($this->ipcConnections[$uid]);
            unset($this->ipcConnections[$uid]);
        }

        return $this;
    }

    /**
     * @param int $uid
     * @return int
     */
    protected function registerWorker(int $uid)
    {
        $socketServer = new SocketServer(0, 500, static::LOOPBACK_INTERFACE);
        $socketServer->setSoTimeout(10);
        $this->ipcServers[$uid] = $socketServer;

        return $socketServer->getLocalPort();
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
}