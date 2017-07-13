<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\Stream\SocketStream;
use Zeus\Networking\SocketServer;

use Zeus\Kernel\ProcessManager\MultiProcessingModule\SharedAddressSpaceInterface;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\SharedInitialAddressSpaceInterface;
use Zeus\Kernel\ProcessManager\WorkerEvent;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;

/**
 * Class SocketMessageBroker
 * @internal
 */
final class SocketMessageBroker
{
    protected $oneServerPerWorker = false;

    /** @var SocketServer */
    protected $server;

    /** @var int */
    protected $lastTickTime = 0;

    /** @var MessageComponentInterface */
    protected $message;

    /** @var SocketStream */
    protected $connection;

    public function __construct(AbstractNetworkServiceConfig $config, MessageComponentInterface $message)
    {
        $this->config = $config;
        $this->message = $message;
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        $events = $events->getSharedManager();
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onStart']);
        $events->attach('*', WorkerEvent::EVENT_WORKER_INIT, [$this, 'onStart']);
        $events->attach('*', WorkerEvent::EVENT_WORKER_LOOP, [$this, 'onWorkerLoop']);
        $events->attach('*', WorkerEvent::EVENT_WORKER_EXIT, [$this, 'onWorkerExit'], 1000);

        return $this;
    }

    /**
     * @param EventInterface $event
     * @return $this
     */
    public function onStart(EventInterface $event)
    {
        if ($event->getName() === SchedulerEvent::EVENT_SCHEDULER_START) {
            $mpm = $event->getTarget()->getMultiProcessingModule();
            if ($mpm instanceof SharedAddressSpaceInterface || $mpm instanceof SharedInitialAddressSpaceInterface) {
                $this->createServer(5);

                return $this;
            }
        };

        if (!$this->server && $event->getName() === WorkerEvent::EVENT_WORKER_INIT) {
            $this->oneServerPerWorker = true;
            $this->createServer(1);
        }

        return $this;
    }

    /**
     * @param int $backlog
     * @return $this
     */
    protected function createServer($backlog)
    {
        $this->server = new SocketServer();
        $this->server->setReuseAddress(true);
        $this->server->setSoTimeout(1000);
        $this->server->bind($this->config->getListenAddress(), $backlog, $this->config->getListenPort());

        return $this;
    }

    /**
     * @param WorkerEvent $event
     * @throws \Throwable|\Exception
     * @throws null
     */
    public function onWorkerLoop(WorkerEvent $event)
    {
        $exception = null;

        if ($this->oneServerPerWorker && $this->server->isClosed()) {
            $this->createServer(1);
        }

        try {
            if (!$this->connection) {
                try {
                    $connection = $this->server->accept();
                    $event->getTarget()->getStatus()->incrementNumberOfFinishedTasks(1);
                    $event->getTarget()->setRunning();
                    if ($this->oneServerPerWorker) {
                        $this->server->close();
                    }
                } catch (SocketTimeoutException $exception) {
                    $event->getTarget()->setWaiting();
                    if ($this->oneServerPerWorker) {
                        $this->server->close();
                    }

                    return;
                }

                $this->connection = $connection;
                $this->message->onOpen($connection);
            }

            $data = '';
            while ($data !== false && $this->connection->isReadable()) {
                $data = $this->connection->read();
                if ($data !== false && $data !== '') {
                    $this->message->onMessage($this->connection, $data);
                }

                $this->onHeartBeat($event);
            }

            // nothing wrong happened, data was handled, resume main event
            if ($this->connection->isReadable() && $this->connection->isWritable()) {
                return;
            }
        } catch (\Throwable $exception) {
        }

        if ($this->connection) {
            if ($exception) {
                try {
                    $this->message->onError($this->connection, $exception);
                } catch (\Throwable $exception) {
                }
            }

            $this->connection->close();
            $this->connection = null;
        }

        $event->getTarget()->setWaiting();

        if ($exception) {
            throw $exception;
        }
    }

    public function onWorkerExit()
    {
        if ($this->oneServerPerWorker && !$this->server->isClosed()) {
            $this->server->close();
        }

        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }
        $this->server = null;
    }

    /**
     * @return SocketServer
     */
    public function getServer()
    {
        return $this->server;
    }

    /**
     * @return $this
     */
    protected function onHeartBeat()
    {
        $now = time();
        if ($this->connection && $this->lastTickTime !== $now) {
            $this->lastTickTime = $now;
            if ($this->message instanceof HeartBeatMessageInterface) {
                $this->message->onHeartBeat($this->connection, []);
            }
        }

        return $this;
    }
}