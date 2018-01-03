<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\Exception\StreamException;
use Zeus\Networking\SocketServer;
use Zeus\Networking\Stream\SocketStream;
use Zeus\ServerService\Shared\Networking\Message\ElectionMessage;
use Zeus\ServerService\Shared\Networking\SocketMessageBroker;

use function time;

class BackendService
{
    /** @var bool */
    private $isLeader = false;

    /** @var int */
    private $lastTickTime = 0;

    /** @var SocketServer */
    private $backendServer;

    /** @var int */
    private $uid;

    /** @var SocketMessageBroker */
    private $messageBroker;

    /** @var string */
    private $workerHost = '127.0.0.3';

    /** @var SocketStream */
    private $connection;

    public function __construct(SocketMessageBroker $messageBroker)
    {
        $this->messageBroker = $messageBroker;
    }

    public function attach(EventManagerInterface $events)
    {
        $events->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $event) {
            $this->onWorkerInit($event);
        }, WorkerEvent::PRIORITY_REGULAR);

        $events->attach(WorkerEvent::EVENT_EXIT, function(WorkerEvent $event) {
            $this->onWorkerExit($event);
        }, 1000);

        $events->attach(WorkerEvent::EVENT_LOOP, function(WorkerEvent $event) {
            if ($this->isLeader) {
                return;
            }

            $this->onWorkerLoop($event);
        }, WorkerEvent::PRIORITY_REGULAR);

        $events->getSharedManager()->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, function(IpcEvent $event) {
            $this->onLeaderElected($event);
        }, WorkerEvent::PRIORITY_FINALIZE);
    }

    private function onLeaderElected(IpcEvent $event)
    {
        $message = $event->getParams();

        if ($message instanceof ElectionMessage) {
            $this->isLeader = true;
            $this->getBackendServer()->close();
        }
    }

    private function onWorkerExit(WorkerEvent $event)
    {
        if ($this->backendServer && !$this->backendServer->isClosed()) {
            $this->backendServer->close();
            $this->backendServer = null;
        }

        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    private function onHeartBeat()
    {
        $now = time();
        if ($this->lastTickTime !== $now) {
            $this->lastTickTime = $now;
            $this->messageBroker->onHeartBeat($this->connection);
        }
    }

    private function onWorkerLoop(WorkerEvent $event)
    {
        $exception = null;

        if ($this->backendServer->isClosed()) {
            $this->startBackendServer($event);
        }

        $leaderPipe = $this->messageBroker->getFrontend()->getLeaderPipe($this->uid, $this->getBackendServer()->getLocalPort());

        if (!$leaderPipe) {
            sleep(1);
            return;
        }

        try {
            if (!$this->connection) {
                try {
                    if ($this->backendServer->getSocket()->select(1000)) {
                        $event->getWorker()->setRunning();
                        $connection = $this->backendServer->accept();
                        $connection->setOption(SO_KEEPALIVE, 1);
                        $connection->setOption(TCP_NODELAY, 1);
                        $event->getWorker()->getStatus()->incrementNumberOfFinishedTasks(1);

                    } else {
                        return;
                    }
                } catch (SocketTimeoutException $exception) {
                    $event->getWorker()->setWaiting();

                    return;
                }

                $this->connection = $connection;
                $this->messageBroker->onOpen($connection);
            }

            if (!$this->connection->isReadable()) {
                $this->connection = null;
                return;
            }
            $this->connection->select(100);

            while ($this->connection->select(0)) {
                $data = $this->connection->read();
                if ($data !== '') {
                    $this->messageBroker->onMessage($this->connection, $data);
                }

                $this->onHeartBeat();
            }

            $this->onHeartBeat();

            // nothing wrong happened, data was handled, resume main event
            if ($this->connection->isReadable() && $this->connection->isWritable()) {
                return;
            }
        } catch (StreamException $streamException) {
            $this->onHeartBeat();
        } catch (\Throwable $exception) {
        }

        if ($this->connection) {
            if ($exception) {
                try {
                    $this->messageBroker->onError($this->connection, $exception);
                } catch (\Throwable $exception) {
                }
            }

            if (!$this->connection->isClosed()) {
                try {
                    $this->connection->flush();
                } catch (\Exception $ex) {
                    // @todo: handle this case?
                }

                try {
                    $this->connection->close();
                } catch (\Exception $ex) {
                    // @todo: handle this case?
                }

            }
            $this->connection = null;
        }

        $event->getTarget()->setWaiting();

        if ($exception) {
            throw $exception;
        }
    }

    public function getBackendServer() : SocketServer
    {
        if (!$this->backendServer) {
            throw new \LogicException("Backend server not initiated");
        }
        return $this->backendServer;
    }

    private function startBackendServer(WorkerEvent $event)
    {
        $server = new SocketServer();
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->workerHost, 1, 0);
        $worker = $event->getWorker();
        $this->uid = $worker->getUid();
        $this->backendServer = $server;
    }

    private function onWorkerInit(WorkerEvent $event)
    {
        $this->startBackendServer($event);
    }
}