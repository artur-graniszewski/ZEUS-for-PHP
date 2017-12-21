<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\Exception\StreamException;
use Zeus\Networking\SocketServer;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;

class FrontendWorker
{
    /** @var bool */
    private $isBusy = false;

    /** @var bool */
    private $isLeader = false;

    /** @var string */
    private $workerHost = '127.0.0.3';

    /** @var SocketMessageBroker */
    private $messageBroker;

    /** @var SocketStream[] */
    private $workerPipe = [];

    /** @var SocketStream[] */
    private $backendStreams = [];

    /** @var int[] */
    private $availableWorkers = [];

    /** @var int[] */
    private $busyWorkers = [];

    /** @var SocketStream[] */
    private $frontendStreams = [];

    /** @var SocketStream[] */
    private $connectionQueue = [];

    /** @var SocketServer */
    private $frontendServer;

    /** @var Selector */
    private $writeSelector;

    /** @var string */
    private $backendHost = '127.0.0.2';

    /** @var Selector */
    private $readSelector;

    /** @var SocketServer */
    private $backendServer;

    public function __construct(SocketMessageBroker $messageBroker)
    {
        $this->messageBroker = $messageBroker;
    }

    public function attach(EventManagerInterface $events)
    {
        $events->attach(WorkerEvent::EVENT_LOOP, function(WorkerEvent $event) {
            if ($this->isLeader && !$this->isBusy) {
                $event->getWorker()->setRunning();
                $this->isBusy = true;
            }
            $this->isLeader ? $this->onLeaderLoop($event) : $this->messageBroker->onWorkerLoop($event);
        }, WorkerEvent::PRIORITY_REGULAR);
    }

    public function getFrontendServer() : SocketServer
    {
        if (!$this->frontendServer) {
            throw new \LogicException("Frontend server not available");
        }
        return $this->frontendServer;
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

    public function onLeaderLoop(WorkerEvent $event)
    {
        //$this->messageBroker->getLogger()->debug("Select");
        $this->readSelector->select(100);
        //$this->messageBroker->getLogger()->debug("Register workers");
        $this->registerWorkers();
        //$this->messageBroker->getLogger()->debug("Unregister workers");
        $this->unregisterWorkers();
        //$this->messageBroker->getLogger()->debug("Add clients");
        $this->addClients();
        //$this->messageBroker->getLogger()->debug("Disconnect clients");
        $this->disconnectClients();
        //$this->messageBroker->getLogger()->debug("Handle clients");
        $this->handleClients();
        //$this->messageBroker->getLogger()->debug("Loop done");
    }

    protected function startFrontendServer(int $backlog)
    {
        $server = new SocketServer();
        $server->setReuseAddress(true);
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->messageBroker->getConfig()->getListenAddress(), $backlog, $this->messageBroker->getConfig()->getListenPort());
        $this->readSelector->register($server->getSocket(), Selector::OP_READ);
        $this->frontendServer = $server;
    }

    public function onLeaderElection(IpcEvent $event)
    {
        $message = $event->getParams();

        if ($message instanceof ElectionMessage) {
            $this->messageBroker->getLogger()->debug("Becoming pool leader");
            $this->readSelector = new Selector();
            $this->writeSelector = new Selector();
            $this->startBackendServer();
            $this->isLeader = true;
            $this->messageBroker->getWorkerServer()->close();
            $this->workerServer = null;
            $this->startFrontendServer(100);

            $this->messageBroker->getLogger()->debug("Sending leader-elected message");
            $event->getTarget()->send(new LeaderElectedMessage($this->backendServer->getLocalAddress()), IpcServer::AUDIENCE_ALL);
            $event->getTarget()->send(new LeaderElectedMessage($this->backendServer->getLocalAddress()), IpcServer::AUDIENCE_SERVER);
        }
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
            $this->messageBroker->getLogger()->warn("Connection pool is full, queuing in effect [$queued downstreams queued, $workers downstreams active]");
        } else if ($queueSize > 0 && !$this->connectionQueue) {
            $available = count($this->availableWorkers);
            $workers = count($this->busyWorkers);
            $this->messageBroker->getLogger()->info("Connection pool is back to normal [$available downstreams idle, $workers downstreams active]");
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
                    $this->messageBroker->getLogger()->err("Could not bind to worker $uid at port $port: [$errno] $errstr");
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

}