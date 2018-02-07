<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerAwareTrait;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\IO\Exception\IOException;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\SocketStream;
use Zeus\ServerService\Shared\Networking\Message\FrontendElectionMessage;
use Zeus\ServerService\Shared\Networking\SocketMessageBroker;

use function time;
use function usleep;

class BackendService
{
    use LoggerAwareTrait;

    /** @var Selector */
    protected $backendServerSelector;

    /** @var bool */
    private $isBackend = true;

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
            $this->startBackendServer($event);
        }, WorkerEvent::PRIORITY_REGULAR);

        $events->attach(WorkerEvent::EVENT_EXIT, function(WorkerEvent $event) {
            $this->onWorkerExit($event);
        }, 1000);

        $events->attach(WorkerEvent::EVENT_LOOP, function(WorkerEvent $event) {
            if (!$this->isBackend) {
                return;
            }

            $this->onWorkerLoop($event);
        }, WorkerEvent::PRIORITY_REGULAR);

        $events->getSharedManager()->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, function(IpcEvent $event) {
            $this->onFrontendElected($event);
        }, WorkerEvent::PRIORITY_FINALIZE);
    }

    private function onFrontendElected(IpcEvent $event)
    {
        $message = $event->getParams();

        // @todo: BackendService should not contain such logic! 
        if ($message instanceof FrontendElectionMessage) {
            $this->isBackend = false;
            $this->getBackendServer()->close();
        }
    }

    private function onWorkerExit(WorkerEvent $event)
    {
        $this->notifyRegistrator(RegistratorService::STATUS_WORKER_GONE);

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
        if (!$this->connection) {
            return;
        }

        $now = time();
        if ($this->lastTickTime !== $now) {
            $this->lastTickTime = $now;
            $this->messageBroker->onHeartBeat($this->connection);
        }
    }

    private function notifyRegistrator(string $status) : bool
    {
        return $this->messageBroker->getRegistrator()->notifyRegistrator($this->uid, $this->getBackendServer()->getLocalPort(), $status);
    }

    private function closeConnection(WorkerEvent $event)
    {
        if (!$this->connection->isClosed()) {
            $this->connection->shutdown(STREAM_SHUT_RD);
            $this->connection->close();
        }

        $event->getWorker()->getStatus()->incrementNumberOfFinishedTasks(1);
        $event->getTarget()->setWaiting();
        $this->connection = null;
    }

    private function onWorkerLoop(WorkerEvent $event)
    {
        $exception = null;

        try {
            if (!$this->connection) {
                if (!$this->notifyRegistrator(RegistratorService::STATUS_WORKER_READY)) {
                    usleep(1000);
                    return;
                }

                try {
                    if ($this->backendServerSelector->select(1000)) {
                        $event->getWorker()->setRunning();
                        $connection = $this->backendServer->accept();
                        try {
                            $connection->setOption(SO_KEEPALIVE, 1);
                            $connection->setOption(TCP_NODELAY, 1);
                        } catch (UnsupportedOperationException $exception) {
                            // this may happen in case of disabled PHP extension, or definitely happen in case of HHVM
                        }
                    } else {
                        return;
                    }
                } catch (\Throwable $exception) {
                    //$this->sendStatusToFrontend(FrontendService::STATUS_WORKER_READY);
                    $event->getWorker()->setWaiting();

                    return;
                }

                $this->connection = $connection;
                $this->messageBroker->onOpen($connection);
            }

            if (!$this->connection->isReadable()) {
                $this->connection = null;
                $event->getWorker()->setWaiting();
                return;
            }

            $selector = new Selector();
            $this->connection->register($selector, SelectionKey::OP_READ);
            while ($selector->select(1000) > 0) {
                $data = $this->connection->read();
                if ($data !== '') {
                    $this->messageBroker->onMessage($this->connection, $data);

                    if ($this->connection->isClosed()) {
                        break;
                    }
                    do {
                        $flushed = $this->connection->flush();
                    } while (!$flushed);
                } else {
                    // its an EOF
                    $this->messageBroker->onClose($this->connection);
                    $this->closeConnection($event);

                    return;
                }
                $this->onHeartBeat();
            }

            // nothing wrong happened, data was handled, resume main event
            if (!$this->connection->isClosed()) {
                $this->onHeartBeat();

                return;
            }
        } catch (IOException $streamException) {
            $this->onHeartBeat();
        } catch (\Throwable $exception) {
        }

        if ($this->connection) {
            try {
                if ($exception) {
                    $this->messageBroker->onError($this->connection, $exception);
                } else {
                    $this->messageBroker->onClose($this->connection);
                }
            } catch (\Throwable $exception) {
            }

            $this->closeConnection($event);
        }

        $event->getWorker()->getStatus()->incrementNumberOfFinishedTasks(1);
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
        $this->backendServerSelector = new Selector();
        $this->backendServerSelector->register($this->backendServer->getSocket(), SelectionKey::OP_ACCEPT);
    }
}