<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;
use Zeus\Networking\SocketServer;
use Zeus\Kernel\ProcessManager\WorkerEvent;
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
    /** @var bool */
    protected $isBusy = false;

    /** @var bool */
    protected $isLeader = false;

    /** @var SocketServer */
    protected $upstreamServer;

    /** @var int */
    protected $lastTickTime = 0;

    /** @var MessageComponentInterface */
    protected $message;

    /** @var SocketStream */
    protected $connection;

    /** @var SocketServer */
    protected $downstreamServer;

    /** @var SocketServer */
    protected $workerServer;

    /** @var SocketStream[] */
    protected $ipc = [];

    /** @var SocketStream[] */
    protected $downstream = [];

    /** @var int[] */
    protected $availableWorkers = [];

    /** @var int[] */
    protected $busyWorkers = [];

    /** @var Selector */
    protected $readSelector;

    /** @var SocketStream[] */
    protected $upstreams = [];

    /** @var SocketStream */
    protected $leaderPipe;

    /** @var SocketStream[] */
    protected $connectionQueue = [];

    /** @var Selector */
    protected $writeSelector;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(AbstractNetworkServiceConfig $config, MessageComponentInterface $message)
    {
        $this->config = $config;
        $this->message = $message;
    }

    /**
     * @param bool $flag
     * @return $this
     */
    public function setIsLeader(bool $flag)
    {
        $this->isLeader = $flag;

        return $this;
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        $events = $events->getSharedManager();
        $events->attach('*', WorkerEvent::EVENT_WORKER_INIT, [$this, 'onWorkerStart'], WorkerEvent::PRIORITY_REGULAR);
        $events->attach('*', WorkerEvent::EVENT_WORKER_LOOP, [$this, 'onWorkerLoop']);
        $events->attach('*', WorkerEvent::EVENT_WORKER_LOOP, [$this, 'onLeaderLoop']);
        $events->attach('*', WorkerEvent::EVENT_WORKER_EXIT, [$this, 'onWorkerExit'], 1000);

        return $this;
    }

    /**
     * @return SocketStream
     */
    public function getLeaderPipe()
    {
        return $this->leaderPipe;
    }

    /**
     * @return SocketServer
     */
    public function getWorkerServer()
    {
        return $this->workerServer;
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
     * @param int $backlog
     * @return $this
     */
    protected function startUpstreamServer(int $backlog)
    {
        $server = new SocketServer();
        $server->setReuseAddress(true);
        $server->setSoTimeout(0);
        $server->bind($this->config->getListenAddress(), $backlog, $this->config->getListenPort());

        $this->upstreamServer = $server;

        return $this;
    }

    /**
     * @return $this
     */
    protected function startDownstreamServer()
    {
        $server = new SocketServer();
        $server->setReuseAddress(true);
        $server->setSoTimeout(0);
        $server->bind('127.0.0.1', 300, 3333);
        $this->readSelector->register($server->getSocket(), Selector::OP_READ);
        $this->downstreamServer = $server;

        return $this;
    }

    /**
     * @param WorkerEvent $event
     * @return $this
     */
    protected function createWorkerServer(WorkerEvent $event)
    {
        $workerServer = new SocketServer();
        $workerServer->setSoTimeout(0);
        $workerServer->setTcpNoDelay(true);
        $workerServer->bind('127.0.0.1', 1, 0);
        $port = $workerServer->getLocalPort();

        $worker = $event->getTarget();
        $uid = $worker->getThreadId() > 1 ? $worker->getThreadId() : $worker->getProcessId();

        $this->workerServer = $workerServer;

        $opts = [
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ];

        $leaderPipe = @stream_socket_client('tcp://127.0.0.1:3333', $errno, $errstr, 100, STREAM_CLIENT_CONNECT, stream_context_create($opts));
        if ($leaderPipe) {
            $leaderPipe = new SocketStream($leaderPipe);
            $leaderPipe->setOption(SO_KEEPALIVE, 1);
            $leaderPipe->write("$uid:$port!")->flush();
            $this->leaderPipe = $leaderPipe;

            return $this;
        }

        $this->readSelector = new Selector();
        $this->writeSelector = new Selector();
        $this->startDownstreamServer();
        $this->isLeader = true;
        $this->workerServer->close();
        $this->workerServer = null;
        $event->getTarget()->setRunning();
        $this->startUpstreamServer(1000);

        return $this;
    }

    /**
     * @param WorkerEvent $event
     */
    public function onWorkerStart(WorkerEvent $event)
    {
        $this->createWorkerServer($event);
    }

    /**
     * @param WorkerEvent $event
     * @throws \Throwable|\Exception
     * @throws null
     */
    public function onLeaderLoop(WorkerEvent $event)
    {
        if (!$this->isLeader) {
            return;
        }

        $this->readSelector->select(1000);
        $this->registerWorkers();
        $this->unregisterWorkers();
        $this->addClients();
        $this->disconnectClients();
        $this->handleClients();
    }

    /**
     * @return $this
     */
    protected function addClients()
    {
        $queueSize = count($this->connectionQueue);
        try {
            $client = true;
            $connectionLimit = 10;
            while ($client && $connectionLimit-- > 0) {
                $client = $this->upstreamServer->accept();
                $this->bindToWorker($client);
            }
        } catch (SocketTimeoutException $exception) {
        }

        foreach ($this->connectionQueue as $key => $client) {
            unset($this->connectionQueue[$key]);
            $this->bindToWorker($client);
        }

        if (count($this->connectionQueue) > $queueSize) {
            $queued = count($this->connectionQueue);
            $workers = count($this->busyWorkers);
            $this->getLogger()->warn("Connection pool is full, queuing in effect [$queued downstreams queued, $workers downstreams active]");
        } else if ($queueSize > 0 && !$this->connectionQueue) {
            $available = count($this->availableWorkers);
            $workers = count($this->busyWorkers);
            $this->getLogger()->info("Connection pool is back to normal [$available downstreams idle, $workers downstreams active]");
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function disconnectClients()
    {
        foreach ($this->upstreams as $key => $stream) {
            if (!$stream->isReadable() || !$stream->isWritable()) {
                $this->disconnectClient($key);
            }
        }

        foreach ($this->downstream as $key => $stream) {
            if (!$stream->isReadable() || !$stream->isWritable()) {
                $this->disconnectClient($key);
            }
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function handleClients()
    {
        if (!$this->upstreams) {

            return $this;
        }

        $now = microtime(true);
        do {
            if (!$this->readSelector->select(0)) {
                return $this;
            }

            $this->registerWorkers();
            $this->addClients();

            $streamsToRead = $this->readSelector->getSelectedStreams(Selector::OP_READ);

            $this->writeSelector->select(0);
            $streamsToWrite = $this->writeSelector->getSelectedStreams(Selector::OP_WRITE);
            if (!$streamsToWrite) {
                break;
            }

            foreach ($streamsToRead as $index => $input) {
                $output = null;
                if ($input->getResourceId() === $this->downstreamServer->getSocket()->getResourceId()) {
                    continue;
                }

                $key = array_search($input, $this->upstreams);

                if ($key !== false) {
                    $output = $this->downstream[$key];
                    $outputName = 'SERVER';
                } else {
                    $key = array_search($input, $this->downstream);
                    if (!$key) {
                        //$this->readSelector->unregister($input);
                        continue;
                    }
                    $output = $this->upstreams[$key];
                    $outputName = 'CLIENT';
                }

                try {
                    if (!$input->isReadable()) {
                        $this->disconnectClient($key);
                        continue;
                    }

                    if ($output->isClosed() || \in_array($output, $streamsToWrite)) {
                        $data = $input->read();

                        if (!isset($data[0])) {
                            continue;
                        }

                        if (!$output->isReadable() || !$output->isWritable()) {
                            $this->disconnectClient($key);
                            continue;
                        }

                        $output->write($data)->flush();
                    }
                } catch (\Exception $exception) {
                    $this->disconnectClient($key);
                    break;
                }
            }
        } while ($streamsToRead && (microtime(true) - $now < 0.1));

        return $this;
    }

    /**
     * @param int $key
     * @return $this
     */
    protected function disconnectClient($key)
    {
        if (isset($this->upstreams[$key])) {
            $stream = $this->upstreams[$key];
            if (!$stream->isClosed()) {
                try {
                    $stream->close();
                } catch (\Exception $exception) {

                }
            }
            $this->readSelector->unregister($stream);
            $this->writeSelector->unregister($stream);
            unset($this->upstreams[$key]);
        }

        if (isset($this->downstream[$key])) {
            $stream = $this->downstream[$key];
            if (!$stream->isClosed()) {
                try {
                    $stream->close();
                } catch (\Exception $exception) {

                }
            }
            $this->readSelector->unregister($stream);
            $this->writeSelector->unregister($stream);
            unset($this->downstream[$key]);
            $this->availableWorkers[$key] = $this->busyWorkers[$key];
            unset($this->busyWorkers[$key]);
        }

        return $this;
    }

    /**
     * @param SocketStream $client
     * @return $this
     */
    protected function bindToWorker(SocketStream $client)
    {
        foreach ($this->availableWorkers as $uid => $port) {
            $opts = [
                'socket' => [
                    'tcp_nodelay' => true,
                ],
            ];

            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0, STREAM_CLIENT_CONNECT, stream_context_create($opts));
            if (!$socket) {
                unset($this->availableWorkers[$uid]);
                continue;
            }

            $downstream = new SocketStream($socket);
            $downstream->setOption(SO_KEEPALIVE, 1);
            $downstream->setBlocking(true);
            $this->downstream[$uid] = $downstream;
            $this->busyWorkers[$uid] = $port;
            unset($this->availableWorkers[$uid]);
            $this->upstreams[$uid] = $client;
            $this->readSelector->register($downstream, Selector::OP_READ);
            $this->readSelector->register($client, Selector::OP_READ);
            $this->writeSelector->register($downstream, Selector::OP_WRITE);
            $this->writeSelector->register($client, Selector::OP_WRITE);

            return $this;
        }

        if (!$this->availableWorkers) {
            $this->connectionQueue[] = $client;
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function registerWorkers()
    {
        $streams = $this->readSelector->getSelectedStreams(Selector::OP_READ);

        if (!in_array($this->downstreamServer->getSocket(), $streams)) {
            return $this;
        }

        try {
            while ($connection = $this->downstreamServer->accept()) {
                if ($connection->select(100)) {
                    $in = $connection->read('!');
                    list($uid, $port) = explode(":", $in);

                    $this->availableWorkers[$uid] = $port;
                    $this->ipc[$uid] = $connection;

                    continue;
                }

                throw new \RuntimeException("Downstream connection is broken");
            }
        } catch (SocketTimeoutException $exception) {

        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function unregisterWorkers()
    {
        foreach ($this->ipc as $uid => $ipc) {
            try {
                //$ipc->write(' ')->flush();

                if (!$ipc->isClosed() && $ipc->isReadable() && $ipc->isWritable()) {
                    continue;
                }
            } catch (\Exception $exception) {

            }

            try {
                $ipc->close();
            } catch (\Exception $exception) {

            }
            $this->disconnectClient($uid);

            unset ($this->ipc[$uid]);
            unset ($this->availableWorkers[$uid]);
            unset ($this->busyWorkers[$uid]);
        }

        return $this;
    }

    /**
     * @param WorkerEvent $event
     * @throws \Throwable|\Exception
     * @throws null
     */
    public function onWorkerLoop(WorkerEvent $event)
    {
        if ($this->isLeader) {
            if ($this->isBusy) {
                return;
            }
            $event->getTarget()->setRunning();
            $this->isBusy = true;
            return;
        }

        $exception = null;

        if ($this->workerServer->isClosed()) {
            $this->createWorkerServer($event);
        }

        try {
            if ($this->leaderPipe->select(0)) {
                $this->leaderPipe->read();
            }
        } catch (\Exception $ex) {
            // @todo: connection severed, leader died, exit
            $event->stopWorker(true);

            return;
        }

//        static $sent = false;
//        if (!$sent) {
//            $event->getTarget()->getIpc()->send('loop from ' . getmypid(), IpcDriver::AUDIENCE_AMOUNT, 2);
//            $sent = true;
//        }
//        $msgs = $event->getTarget()->getIpc()->readAll();
//        foreach ($msgs as $msg) {
//            trigger_error(getmypid() . " RECEIVED $msg");
//        }

        try {
            if (!$this->connection) {
                try {
                    if ($this->workerServer->getSocket()->select(1000)) {
                        $event->getTarget()->setRunning();
                        $connection = $this->workerServer->accept();
                        // @todo: remove setBlocking(), now its needed in ZeusTest\SocketMessageBrokerTest unit tests, otherwise they hang
                        $connection->setBlocking(false);
                        $event->getTarget()->getStatus()->incrementNumberOfFinishedTasks(1);

                    } else {
                        return;
                    }
                } catch (SocketTimeoutException $exception) {
                    $event->getTarget()->setWaiting();

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

            while ($this->connection->isReadable() && $this->connection->select(0)) {
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
                $this->connection->close();
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
        $this->upstreamServer = null;
    }

    /**
     * @return SocketServer
     */
    public function getUpstreamServer()
    {
        return $this->upstreamServer;
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