<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\Networking\Stream\SocketStream;
use Zeus\Kernel\Networking\SocketServer;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\SeparateAddressSpaceInterface;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\SharedAddressSpaceInterface;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\SharedInitialAddressSpaceInterface;
use Zeus\Kernel\ProcessManager\ProcessEvent;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

/**
 * Class SocketEventSubscriber
 * @internal
 */
final class SocketEventSubscriber
{
    /** @var SocketServer */
    protected $server;

    /** @var int */
    protected $lastTickTime = 0;

    /** @var MessageComponentInterface */
    protected $message;

    /** @var SocketStream */
    protected $connection;

    public function __construct(SocketServer $server, MessageComponentInterface $message)
    {
        $this->server = $server;
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
     * @return $this
     */
    public function onServerStart(EventInterface $event)
    {
        if ($event->getName() === SchedulerEvent::EVENT_SCHEDULER_START
            //&&
            //($event->getScheduler() instanceof SharedAddressSpaceInterface || $event->getScheduler() instanceof SharedInitialAddressSpaceInterface)) {
        ) {
            $this->server->createServer();

            return $this;
        };

        if ($event->getName() === ProcessEvent::EVENT_PROCESS_INIT && $event->getTarget() instanceof SeparateAddressSpaceInterface) {
            $this->server->createServer();
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
                $connection = $this->server->listen(1);
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
        } catch (\Exception $exception) {
        } catch (\Throwable $exception) {
        }

        if ($this->connection) {
            if ($exception) {
                try {
                    $this->message->onError($this->connection, $exception);
                } catch (\Exception $exception) {
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

        $this->server = null;
    }

    /**
     * @param ProcessEvent $event
     * @return $this
     */
    protected function onHeartBeat(ProcessEvent $event)
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