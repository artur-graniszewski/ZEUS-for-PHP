<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\Networking\SocketStream;
use Zeus\Kernel\Networking\SocketServer;
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
     * @throws \Throwable|\Exception
     * @throws null
     */
    public function onProcessLoop(SchedulerEvent $event)
    {
        $exception = null;

        try {
            if (!$this->connection) {
                $connection = $this->server->listen(1);
                if (!$connection) {
                    return;
                }

                $event->getProcess()->setRunning();
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

        $event->getProcess()->setWaiting();

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
     * @param SchedulerEvent $event
     * @return $this
     */
    protected function onHeartBeat(SchedulerEvent $event)
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