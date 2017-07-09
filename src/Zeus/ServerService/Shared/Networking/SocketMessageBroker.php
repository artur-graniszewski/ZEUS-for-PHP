<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\Networking\Stream\SocketStream;
use Zeus\Kernel\Networking\SocketServer;

use Zeus\Kernel\ProcessManager\MultiProcessingModule\SharedAddressSpaceInterface;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\SharedInitialAddressSpaceInterface;
use Zeus\Kernel\ProcessManager\ProcessEvent;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;

/**
 * Class SocketMessageBroker
 * @internal
 */
final class SocketMessageBroker
{
    protected $stopServerAtProcessExit = false;

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
        $events->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onServerStart']);
        $events->attach('*', ProcessEvent::EVENT_PROCESS_INIT, [$this, 'onServerStart']);
        $events->attach('*', ProcessEvent::EVENT_PROCESS_LOOP, [$this, 'onProcessLoop']);
        $events->attach('*', SchedulerEvent::EVENT_PROCESS_EXIT, [$this, 'onProcessExit'], 1000);

        return $this;
    }

    /**
     * @param EventInterface $event
     * @return $this
     */
    public function onServerStart(EventInterface $event)
    {
        if ($event->getName() === SchedulerEvent::EVENT_SCHEDULER_START) {
            $mpm = $event->getTarget()->getMultiProcessingModule();
            if ($mpm instanceof SharedAddressSpaceInterface || $mpm instanceof SharedInitialAddressSpaceInterface) {
                $this->server = new SocketServer($this->config->getListenPort(), null, $this->config->getListenAddress());

                return $this;
            }
        };

        if ($event->getName() === ProcessEvent::EVENT_PROCESS_INIT && !$this->server) {
            $this->stopServerAtProcessExit = true;
            $this->server = new SocketServer();
            $this->server->setReuseAddress(true);
            $this->server->bind($this->config->getListenAddress(), 1, $this->config->getListenPort());
        }

        return $this;
    }

    /**
     * @param ProcessEvent $event
     * @throws \Throwable|\Exception
     * @throws null
     */
    public function onProcessLoop(ProcessEvent $event)
    {
        $exception = null;

        try {
            if (!$this->connection) {
                $connection = $this->server->accept(1);
                if (!$connection) {
                    return;
                }

                $event->getTarget()->setRunning();
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

    public function onProcessExit()
    {
        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }

        if ($this->stopServerAtProcessExit) {
            $this->server->close();
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