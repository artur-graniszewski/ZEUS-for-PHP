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

    /** @var int */
    protected $tickInterval = 1;

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
     * @param int $tickInterval
     * @return $this
     */
    public function setHeartBeatInterval($tickInterval)
    {
        $this->tickInterval = $tickInterval;

        return $this;
    }

    /**
     * @return int
     */
    public function getHeartBeatInterval()
    {
        return $this->tickInterval;
    }


    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerStart']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_LOOP, [$this, 'onTaskLoop']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_EXIT, [$this, 'onTaskExit']);

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
     * @return $this
     */
    public function onTaskLoop(SchedulerEvent $event)
    {
        $exception = null;

        try {
            if (!$this->connection) {
                if ($connection = $this->server->listen(1)) {
                    $event->getProcess()->setRunning(time());
                    $this->message->onOpen($connection);
                    $this->connection = $connection;
                }
            }

            if ($this->connection) {
                if ($this->connection->isReadable()) {
                    if ($this->connection->select(1)) {
                        $this->message->onMessage($this->connection, $this->connection->read());
                    }


                    $this->heartBeat();
                }

                $event->getProcess()->setWaiting();
                if (!$this->connection->isReadable()) {
                    $this->connection = null;
                    return $this;
                }
            }
        } catch (\Exception $exception) {

        } catch (\Throwable $exception) {

        }

        if ($exception) {
            if ($this->connection) {
                $this->message->onError($this->connection, $exception);
            }

            $event->getProcess()->setWaiting();

            throw $exception;
        }

        return $this;
    }

    public function onTaskExit()
    {
        if ($this->connection->isReadable()) {
            $this->connection->close();
            sleep(1);
            trigger_error("EXITING");
            $this->connection = null;
        }
    }

    /**
     * @return $this
     */
    public function heartBeat()
    {
        $now = time();
        if ($this->lastTickTime !== $now) {
            $this->lastTickTime = $now;
            $this->message->onHeartBeat($this->connection, []);
        }

        return $this;
    }
}