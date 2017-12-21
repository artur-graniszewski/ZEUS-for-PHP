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
    /** @var bool */
    private $isBusy = false;

    /** @var bool */
    private $isLeader = false;

    /** @var SocketServer */
    private $frontendServer;

    /** @var int */
    private $lastTickTime = 0;

    /** @var MessageComponentInterface */
    private $message;

    /** @var SocketStream */
    private $connection;

    /** @var SocketServer */
    private $backendServer;

    /** @var SocketServer */
    private $workerServer;

    /** @var SocketStream[] */
    private $workerPipe = [];

    /** @var SocketStream[] */
    private $backendStreams = [];

    /** @var int[] */
    private $availableWorkers = [];

    /** @var int[] */
    private $busyWorkers = [];

    /** @var Selector */
    private $readSelector;

    /** @var SocketStream[] */
    private $frontendStreams = [];

    /** @var SocketStream */
    private $leaderPipe;

    /** @var SocketStream[] */
    private $connectionQueue = [];

    /** @var Selector */
    private $writeSelector;

    /** @var int */
    private $uid;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $leaderIpcAddress;

    /** @var string */
    private $workerHost = '127.0.0.3';

    /** @var string */
    private $backendHost = '127.0.0.2';

    public function __construct(AbstractNetworkServiceConfig $config, MessageComponentInterface $message)
    {
        $this->config = $config;
        $this->message = $message;
    }

    public function attach(EventManagerInterface $events)
    {
        $events->attach(WorkerEvent::EVENT_INIT, [$this, 'onWorkerInit'], WorkerEvent::PRIORITY_REGULAR);
        $events->attach(WorkerEvent::EVENT_LOOP, function(WorkerEvent $event) {
            if ($this->isLeader && !$this->isBusy) {
                $event->getWorker()->setRunning();
                $this->isBusy = true;
            }
            $this->isLeader ? $this->onLeaderLoop($event) : $this->onWorkerLoop($event);
        }, WorkerEvent::PRIORITY_REGULAR);
        $events->attach(WorkerEvent::EVENT_EXIT, [$this, 'onWorkerExit'], 1000);
        $events->attach(WorkerEvent::EVENT_CREATE, [$this, 'onWorkerCreate'], 1000);
        $events->attach(SchedulerEvent::EVENT_START, [$this, 'startLeaderElection'], SchedulerEvent::PRIORITY_FINALIZE + 1);
        $events->getSharedManager()->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, [$this, 'onLeaderElection'], SchedulerEvent::PRIORITY_FINALIZE);
        $events->getSharedManager()->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, [$this, 'onLeaderElected'], SchedulerEvent::PRIORITY_FINALIZE);
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

    public function startLeaderElection(SchedulerEvent $event)
    {
        $this->getLogger()->debug("Electing pool leader");
        $event->getScheduler()->getIpc()->send(new ElectionMessage(), IpcServer::AUDIENCE_AMOUNT, 1);
    }

    public function onLeaderElected(IpcEvent $event)
    {
        $message = $event->getParams();
        if ($message instanceof LeaderElectedMessage) {
            /** @var LeaderElectedMessage $message */

            //$this->getLogger()->debug("Announcing communication readiness on " . $message->getIpcAddress());
            $this->setLeaderIpcAddress($message->getIpcAddress());
        }
    }

    public function setLeaderIpcAddress(string $address)
    {
        $this->leaderIpcAddress = $address;
    }

    public function onLeaderElection(IpcEvent $event)
    {
        $message = $event->getParams();

        if ($message instanceof ElectionMessage) {
            $this->getLogger()->debug("Becoming pool leader");
            $this->readSelector = new Selector();
            $this->writeSelector = new Selector();
            $this->startBackendServer();
            $this->isLeader = true;
            $this->workerServer->close();
            $this->workerServer = null;
            $this->startFrontendServer(100);

            $this->getLogger()->debug("Sending leader-elected message");
            $event->getTarget()->send(new LeaderElectedMessage($this->backendServer->getLocalAddress()), IpcServer::AUDIENCE_ALL);
            $event->getTarget()->send(new LeaderElectedMessage($this->backendServer->getLocalAddress()), IpcServer::AUDIENCE_SERVER);
        }
    }

    public function getWorkerServer() : SocketServer
    {
        return $this->workerServer;
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

    protected function startFrontendServer(int $backlog)
    {
        $server = new SocketServer();
        $server->setReuseAddress(true);
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->config->getListenAddress(), $backlog, $this->config->getListenPort());
        $this->readSelector->register($server->getSocket(), Selector::OP_READ);
        $this->frontendServer = $server;
    }

    protected function startBackendServer()
    {
        $server = new SocketServer();
        $server->setReuseAddress(true);
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->backendHost, 100, 0);
        $this->readSelector->register($server->getSocket(), Selector::OP_READ);
        $this->backendServer = $server;
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

    public function onLeaderLoop(WorkerEvent $event)
    {
        //$this->getLogger()->debug("Select");
        $this->readSelector->select(100);
        //$this->getLogger()->debug("Register workers");
        $this->registerWorkers();
        //$this->getLogger()->debug("Unregister workers");
        $this->unregisterWorkers();
        //$this->getLogger()->debug("Add clients");
        $this->addClients();
        //$this->getLogger()->debug("Disconnect clients");
        $this->disconnectClients();
        //$this->getLogger()->debug("Handle clients");
        $this->handleClients();
        //$this->getLogger()->debug("Loop done");
    }

    protected function addClients()
    {
        $queueSize = count($this->connectionQueue);
        try {
            $client = true;
            $connectionLimit = 10;
            while ($this->availableWorkers && $client && $connectionLimit-- > 0) {
                $client = $this->getFrontendServer()->accept();
                $client->setOption(TCP_NODELAY, 1);
                $client->setOption(SO_KEEPALIVE, 1);
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
    }

    protected function disconnectClients()
    {
        foreach ($this->frontendStreams as $key => $stream) {
            if (!$stream->isReadable() || !$stream->isWritable()) {
                $this->disconnectClient($key);
            }
        }

        foreach ($this->backendStreams as $key => $stream) {
            if (!$stream->isReadable() || !$stream->isWritable()) {
                $this->disconnectClient($key);
            }
        }
    }

    protected function handleClients()
    {
        if (!$this->frontendStreams) {

            return;
        }

        $now = microtime(true);
        do {
            if (!$this->readSelector->select(0)) {
                return;
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
                if ($input->getResourceId() === $this->backendServer->getSocket()->getResourceId()) {
                    continue;
                }

                $key = array_search($input, $this->frontendStreams);

                if ($key !== false) {
                    $output = $this->backendStreams[$key];
                    $outputName = 'SERVER';
                } else {
                    $key = array_search($input, $this->backendStreams);
                    if (!$key) {
                        //$this->readSelector->unregister($input);
                        continue;
                    }
                    $output = $this->frontendStreams[$key];
                    $outputName = 'CLIENT';
                }

                try {
//                    if (!$input->isReadable()) {
//                        $this->disconnectClient($key);
//                        continue;
//                    }

                    if ($output->isClosed() || \in_array($output, $streamsToWrite)) {
                        $data = $input->read();

                        if (!isset($data[0])) {
                            continue;
                        }

                        if (!$output->isReadable() || !$output->isWritable()) {
                            $this->disconnectClient($key);
                            continue;
                        }

                        $output->write($data);
                        $output->flush();
                    }
                } catch (\Exception $exception) {
                    $this->disconnectClient($key);
                    break;
                }
            }
        } while ($streamsToRead && (microtime(true) - $now < 0.01));

        return;
    }

    protected function disconnectClient(int $key)
    {
        if (isset($this->frontendStreams[$key])) {
            $stream = $this->frontendStreams[$key];
            try {
                $stream->select(0);
                if (!$stream->isClosed()) {
                    $stream->close();
                }
            } catch (\Exception $exception) {

            }
            $this->readSelector->unregister($stream);
            $this->writeSelector->unregister($stream);
            unset($this->frontendStreams[$key]);
        }

        if (isset($this->backendStreams[$key])) {
            $stream = $this->backendStreams[$key];
            try {
                $stream->select(0);
                if (!$stream->isClosed()) {
                    $stream->close();
                }
            } catch (\Exception $exception) {

            }

            $this->readSelector->unregister($stream);
            $this->writeSelector->unregister($stream);
            unset($this->backendStreams[$key]);
            $this->availableWorkers[$key] = $this->busyWorkers[$key];
            unset($this->busyWorkers[$key]);
        }
    }

    private function checkBackendConnection($uid)
    {
        $ipc = $this->workerPipe[$uid];
        try {
            $ipc->write(' ');
            $ipc->flush();

            return;

        } catch (StreamException $exception) {

        }

        try {
            $ipc->close();
        } catch (StreamException $exception) {

        }
        //$this->getLogger()->debug("Disconnecting worker $uid");
        $this->disconnectClient($uid);

        unset ($this->workerPipe[$uid]);
        unset ($this->availableWorkers[$uid]);
        unset ($this->busyWorkers[$uid]);
    }

    protected function bindToWorker(SocketStream $client)
    {
        foreach ($this->availableWorkers as $uid => $port) {
            $opts = [
                'socket' => [
                    'tcp_nodelay' => true,
                ],
            ];
            $host = $this->workerHost;

            $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 0, STREAM_CLIENT_CONNECT, stream_context_create($opts));
            if (!$socket) {
                //unset($this->availableWorkers[$uid]);
                $this->checkBackendConnection($uid);

                if (isset($this->availableWorkers[$uid])) {
                    $this->getLogger()->err("Could not bind to worker $uid at port $port: [$errno] $errstr");
                }

                continue;
            }

            $downstream = new SocketStream($socket);
            $downstream->setOption(SO_KEEPALIVE, 1);
            $downstream->setOption(TCP_NODELAY, 1);
            $downstream->setBlocking(true);
            $this->backendStreams[$uid] = $downstream;
            $this->busyWorkers[$uid] = $port;
            unset($this->availableWorkers[$uid]);
            $this->frontendStreams[$uid] = $client;
            $this->readSelector->register($downstream, Selector::OP_READ);
            $this->readSelector->register($client, Selector::OP_READ);
            $this->writeSelector->register($downstream, Selector::OP_WRITE);
            $this->writeSelector->register($client, Selector::OP_WRITE);

            return;
        }

        $this->connectionQueue[] = $client;
    }

    protected function registerWorkers()
    {
        $streams = $this->readSelector->getSelectedStreams(Selector::OP_READ);
        $registeredWorkers = 0;

//        $connected = json_encode(array_keys($this->workerPipe));
//        $busy = json_encode(array_keys($this->busyWorkers));
//        $free = json_encode(array_keys($this->availableWorkers));
//        $clients = json_encode(array_keys($this->frontendStreams));
//        $this->getLogger()->debug("Already connected: " . $connected . ", busy: " . $busy . ", available: " . $free . ", clients: " . $clients);

        if (!in_array($this->backendServer->getSocket(), $streams)) {
            return;
        }

        try {
            while ($connection = $this->backendServer->accept()) {
                $in = false;
                $connection->setOption(TCP_NODELAY, 1);
                $connection->setOption(SO_KEEPALIVE, 1);
                if ($connection->select(100)) {
                    while (false === $in) {
                        $in = $connection->read('!');
                    }
                    list($uid, $port) = explode(":", $in);

                    $this->availableWorkers[$uid] = $port;
                    $this->workerPipe[$uid] = $connection;
                    $registeredWorkers++;
                    //$this->getLogger()->debug("Registered worker #$uid");

                    continue;
                }

                throw new \RuntimeException("Downstream connection is broken");
            }
        } catch (SocketTimeoutException $exception) {

        }

        if ($registeredWorkers) {
            //$this->getLogger()->debug("Registered $registeredWorkers workers");
        }
    }

    protected function unregisterWorkers()
    {
        foreach ($this->workerPipe as $uid => $ipc) {
            $this->checkBackendConnection($uid);
        }
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

        $this->frontendServer = null;
    }

    public function getFrontendServer() : SocketServer
    {
        if (!$this->frontendServer) {
            throw new \LogicException("Frontend server not available");
        }
        return $this->frontendServer;
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