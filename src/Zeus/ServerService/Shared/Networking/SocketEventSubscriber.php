<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

/**
 * Class ReactEventSubscriber
 * @package Zeus\ServerService\Shared\React
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

    /** @var SocketConnection */
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
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerStart']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_LOOP, [$this, 'onProcessLoop']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_EXIT, [$this, 'onProcessExit']);

        return $this;
    }

    /**
     * @return $this
     */
    public function onSchedulerStart()
    {
        $this->server->createServer();

        return $this;
    }


    /**
     * @param SchedulerEvent $event
     */
    public function onProcessLoop(SchedulerEvent $event)
    {
        $exception = null;

        try {
            if (!$this->connection) {
                $event->getProcess()->setWaiting();

                if ($connection = $this->server->listen(1)) {
                    $event->getProcess()->setRunning(time());
                    $this->message->onOpen($connection);
                    $this->connection = $connection;
                }
            }

            if (!$this->connection || !$this->connection->isReadable()) {
                $this->connection = null;
                return;
            }

            if ($this->connection->isReadable()) {
                do {
                    $data = $this->connection->read();
                    if ($data !== false && $data !== '') {
                        $this->message->onMessage($this->connection, $data);
                    }

                    $this->onHeartBeat();
                } while ($data !== false && $this->connection && $this->connection->isReadable());
            }

            $this->onHeartBeat();

        } catch (\Exception $exception) {

        } catch (\Throwable $exception) {

        }

        if ($exception) {
            if ($this->connection) {
                $this->message->onError($this->connection, $exception);
                $this->connection->close();
                $this->connection = null;
            }

            $event->getProcess()->setWaiting();

            throw $exception;
        }

        return;
    }

    public function onProcessExit()
    {
        if ($this->connection && $this->connection->isReadable()) {
            $this->connection->close();
            $this->connection = null;
        }

        unset($this->server);
    }

    /**
     * @return $this
     */
    public function onHeartBeat()
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