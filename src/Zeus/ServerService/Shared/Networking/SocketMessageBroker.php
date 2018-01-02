<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\Exception\StreamException;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;
use Zeus\Networking\SocketServer;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;

use function microtime;
use function stream_socket_client;
use function count;
use function in_array;
use function array_search;
use function explode;
use function time;

/**
 * Class SocketMessageBroker
 * @internal
 */
final class SocketMessageBroker
{
    /** @var int */
    private $lastTickTime = 0;

    /** @var MessageComponentInterface */
    private $message;

    /** @var SocketStream */
    private $connection;

    /** @var SocketServer */
    private $workerServer;

    /** @var SocketStream */
    private $leaderPipe;

    /** @var int */
    private $uid;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $leaderIpcAddress;

    /** @var FrontendWorker */
    private $frontendWorker;

    /** @var string */
    private $workerHost = '127.0.0.3';

    public function __construct(AbstractNetworkServiceConfig $config, MessageComponentInterface $message)
    {
        $this->config = $config;
        $this->message = $message;
        $this->frontendWorker = new FrontendWorker($this);
    }

    public function getFrontendWorker() : FrontendWorker
    {
        return $this->frontendWorker;
    }

    public function attach(EventManagerInterface $events)
    {
        $this->frontendWorker->attach($events);
        $events->attach(WorkerEvent::EVENT_INIT, [$this, 'onWorkerInit'], WorkerEvent::PRIORITY_REGULAR);
        $events->attach(WorkerEvent::EVENT_EXIT, [$this, 'onWorkerExit'], 1000);
        $events->attach(WorkerEvent::EVENT_CREATE, [$this, 'onWorkerCreate'], 1000);
    }

    public function onWorkerCreate(WorkerEvent $event)
    {
        if ($this->leaderIpcAddress) {
            //$this->getLogger()->debug("Contacting with existing leader on " . $this->leaderIpcAddress);
            $event->setParam('leaderIpcAddress', $this->leaderIpcAddress);
        }
    }

    /**
     * @return SocketStream|null
     */
    public function getLeaderPipe()
    {
        if ($this->leaderPipe) {
            return $this->leaderPipe;
        }

        if (!$this->leaderIpcAddress) {
            return;
        }

        $opts = [
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ];

        //$this->getLogger()->debug("Registering worker on {$this->leaderIpcAddress}");

        $leaderPipe = @stream_socket_client('tcp://' . $this->leaderIpcAddress, $errno, $errstr, 0, STREAM_CLIENT_CONNECT, stream_context_create($opts));
        if ($leaderPipe) {
            $port = $this->workerServer->getLocalPort();
            $uid = $this->uid;

            $leaderPipe = new SocketStream($leaderPipe);
            $leaderPipe->setOption(SO_KEEPALIVE, 1);
            $leaderPipe->setOption(TCP_NODELAY, 1);
            $leaderPipe->write("$uid:$port!");
            $leaderPipe->flush();
            $this->leaderPipe = $leaderPipe;
        } else {
            $this->getLogger()->err("Could not connect to leader: $errstr [$errno]");
        }

        return $this->leaderPipe;
    }

    public function setLeaderIpcAddress(string $address)
    {
        $this->leaderIpcAddress = $address;
    }

    public function getWorkerServer() : SocketServer
    {
        return $this->workerServer;
    }

    public function getConfig() : AbstractNetworkServiceConfig
    {
        return $this->config;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getLogger() : LoggerInterface
    {
        if (!isset($this->logger)) {
            throw new \LogicException("Logger not available");
        }
        return $this->logger;
    }

    protected function createWorkerServer(WorkerEvent $event)
    {
        $server = new SocketServer();
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->workerHost, 1, 0);
        $worker = $event->getWorker();
        $this->uid = $worker->getUid();
        $this->workerServer = $server;
    }

    public function onWorkerInit(WorkerEvent $event)
    {
        $this->leaderIpcAddress = $event->getParam('leaderIpcAddress', $this->leaderIpcAddress);

        if ($this->leaderIpcAddress) {
            //$this->getLogger()->debug("Contacting with new leader on " . $this->leaderIpcAddress);
        }
        $this->createWorkerServer($event);
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
        $exception = null;

        if ($this->workerServer->isClosed()) {
            $this->createWorkerServer($event);
        }

        $leaderPipe = $this->getLeaderPipe();

        if (!$leaderPipe) {
            sleep(1);
            return;
        }

        try {
            if ($this->leaderPipe->select(0)) {
                $this->leaderPipe->read();
            }
        } catch (\Exception $ex) {
            // @todo: connection severed, leader died, exit
            $event->getWorker()->setIsTerminating(true);
            throw new \RuntimeException("Connection with session leader is broken, exiting");
        }

        try {
            if (!$this->connection) {
                try {
                    if ($this->workerServer->getSocket()->select(1000)) {
                        $event->getWorker()->setRunning();
                        $connection = $this->workerServer->accept();
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
                $this->message->onOpen($connection);
            }

            if (!$this->connection->isReadable()) {
                $this->connection = null;
                return;
            }
            $this->connection->select(100);

            while ($this->connection->select(0)) {
                $data = $this->connection->read();
                if ($data !== '') {
                    $this->message->onMessage($this->connection, $data);
                }

                $this->onHeartBeat($event);
            }

            $this->onHeartBeat($event);

            // nothing wrong happened, data was handled, resume main event
            if ($this->connection->isReadable() && $this->connection->isWritable()) {
                return;
            }
        } catch (StreamException $streamException) {
            $this->onHeartBeat($event);
        } catch (\Throwable $exception) {
        }

        if ($this->connection) {
            if ($exception) {
                try {
                    $this->message->onError($this->connection, $exception);
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

    public function onWorkerExit()
    {
        if ($this->workerServer && !$this->workerServer->isClosed()) {
            $this->workerServer->close();
            $this->workerServer = null;
        }

        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }

        if ($this->leaderPipe && !$this->leaderPipe->isClosed()) {
            $this->leaderPipe->close();
            $this->leaderPipe = null;
        }
    }

    protected function onHeartBeat()
    {
        $now = time();
        if ($this->connection && $this->lastTickTime !== $now) {
            $this->lastTickTime = $now;
            if ($this->message instanceof HeartBeatMessageInterface) {
                $this->message->onHeartBeat($this->connection, []);
            }
        }
    }
}