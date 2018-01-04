<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Networking\Exception\StreamException;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\Networking\SocketServer;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;

use function microtime;
use function stream_socket_client;
use function count;
use function in_array;
use function array_search;
use function explode;
use Zeus\ServerService\Shared\Networking\Message\ElectionMessage;
use Zeus\ServerService\Shared\Networking\Message\LeaderElectedMessage;
use Zeus\ServerService\Shared\Networking\SocketMessageBroker;

class FrontendService
{
    /** @var SocketStream[] */
    protected $lingeringStreams = [];

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
    private $registratorServer;

    /** @var string */
    private $registratorAddress;

    /** @var SocketStream */
    private $registratorPipe;

    /** @var resource */
    private $streamContext;

    public function __construct(SocketMessageBroker $messageBroker)
    {
        $this->messageBroker = $messageBroker;
        $this->streamContext = stream_context_create([
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ]);
    }

    public function attach(EventManagerInterface $events)
    {
        $events->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $event) {
            $this->onWorkerInit($event);
        }, WorkerEvent::PRIORITY_REGULAR + 1);

        $events->attach(WorkerEvent::EVENT_LOOP, function(WorkerEvent $event) {
            if (!$this->isLeader) {
                try {
                    if ($this->registratorPipe && $this->registratorPipe->select(0)) {
                        $this->registratorPipe->read();
                    }
                } catch (\Exception $ex) {
                    // @todo: connection severed, leader died, exit
                    $event->getWorker()->setIsTerminating(true);
                    throw new \RuntimeException("Connection with session leader is broken, exiting");
                }

                return;
            }

            if (!$this->isBusy) {
                $event->getWorker()->setRunning();
                $this->isBusy = true;
            }

            $this->onLeaderLoop($event);
        }, WorkerEvent::PRIORITY_REGULAR);

        $events->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) {
            $this->startLeaderElection($event);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $events->getSharedManager()->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, function(IpcEvent $event) {
            $this->onLeaderElection($event);
        }, WorkerEvent::PRIORITY_FINALIZE);

        $events->attach(WorkerEvent::EVENT_CREATE, function(WorkerEvent $event) {
            $this->onWorkerCreate($event);
        }, 1000);

        $events->attach(WorkerEvent::EVENT_EXIT, function(WorkerEvent $event) {
            $this->onWorkerExit($event);
        }, 1000);
    }

    public function getFrontendServer() : SocketServer
    {
        if (!$this->frontendServer) {
            throw new \LogicException("Frontend server not available");
        }

        return $this->frontendServer;
    }

    private function onWorkerCreate(WorkerEvent $event)
    {
        if ($this->registratorAddress) {
            $event->setParam('leaderIpcAddress', $this->registratorAddress);
        }
    }

    public function onWorkerInit(WorkerEvent $event)
    {
        $this->registratorAddress = $event->getParam('leaderIpcAddress', $this->registratorAddress);
    }

    private function startLeaderElection(SchedulerEvent $event)
    {
        $this->messageBroker->getLogger()->debug("Electing pool leader");
        $event->getScheduler()->getIpc()->send(new ElectionMessage(), IpcServer::AUDIENCE_AMOUNT, 1);
    }

    private function onLeaderLoop(WorkerEvent $event)
    {
        $now = microtime(true);
        do {
            //$this->messageBroker->getLogger()->debug("Select");
            if (!$this->readSelector->select(100)) {
                return;
            }

            $this->handleBackends();
            $streams = $this->readSelector->getSelectedStreams();
            foreach ($streams as $index => $stream) {
                $resourceId = $stream->getResourceId();
                if ($resourceId === $this->registratorServer->getSocket()->getResourceId()) {
                    $this->addBackends();

                    unset ($streams[$index]);
                    continue;
                }

                if ($resourceId === $this->frontendServer->getSocket()->getResourceId()) {
                    $this->addClients();

                    unset ($streams[$index]);
                    continue;
                }
            }

            $this->drainStreams();
            $this->disconnectClients();

            if ($streams) {
                $this->handleClients($streams);
            }
        } while ((microtime(true) - $now < 1));
    }

    private function startRegistratorServer()
    {
        $server = new SocketServer();
        $server->setReuseAddress(true);
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->backendHost, 100, 0);
        $this->readSelector->register($server->getSocket(), Selector::OP_READ);
        $this->registratorServer = $server;
    }

    private function startFrontendServer(int $backlog)
    {
        $server = new SocketServer();
        $server->setReuseAddress(true);
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->messageBroker->getConfig()->getListenAddress(), $backlog, $this->messageBroker->getConfig()->getListenPort());
        $this->readSelector->register($server->getSocket(), Selector::OP_READ);
        $this->frontendServer = $server;
    }

    private function onLeaderElection(IpcEvent $event)
    {
        $message = $event->getParams();

        if ($message instanceof LeaderElectedMessage) {
            /** @var LeaderElectedMessage $message */
            $this->setRegistratorAddress($message->getIpcAddress());

            return;
        }


        if (!$message instanceof ElectionMessage) {
            return;
        }

        $this->messageBroker->getLogger()->debug("Becoming frontend worker");
        $this->readSelector = new Selector();
        $this->writeSelector = new Selector();
        $this->startRegistratorServer();
        $this->isLeader = true;
        $this->workerServer = null;
        $this->startFrontendServer(100);

        $this->messageBroker->getLogger()->debug("Asking backend workers to register themselves");
        $event->getTarget()->send(new LeaderElectedMessage($this->registratorServer->getLocalAddress()), IpcServer::AUDIENCE_ALL);
        $event->getTarget()->send(new LeaderElectedMessage($this->registratorServer->getLocalAddress()), IpcServer::AUDIENCE_SERVER);
    }

    private function onWorkerExit(WorkerEvent $event)
    {
        if ($this->registratorPipe && !$this->registratorPipe->isClosed()) {
            $this->registratorPipe->close();
            $this->registratorPipe = null;
        }
    }

    public function setRegistratorAddress(string $address)
    {
        $this->registratorAddress = $address;
    }

    private function addClients()
    {
        $queueSize = count($this->connectionQueue);
        try {
            $connectionLimit = 20;
            $client = null;
            if ($this->availableWorkers) {
                do {
                    $client = $this->getFrontendServer()->accept();
                    //$this->messageBroker->getLogger()->err("Connected");
                    $this->setStreamOptions($client);
                    $this->connectToBackend($client);
                } while ($client && $connectionLimit-- > 0);
            }

            if ($client && !$this->availableWorkers) {
                $this->messageBroker->getLogger()->err("Workers pool exhausted");
            }
        } catch (SocketTimeoutException $exception) {
        }

        foreach ($this->connectionQueue as $key => $client) {
            unset($this->connectionQueue[$key]);
            $this->connectToBackend($client);
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

    private function disconnectClients()
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

    /**
     * @param SocketStream[] $streamsToRead
     */
    private function handleClients($streamsToRead)
    {
        if (!$this->writeSelector->select(0)) {
            return;
        }

        foreach ($streamsToRead as $input) {
            $output = null;
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
                while ($input->select(0)) {
                    $data = $input->read();

                    //$this->messageBroker->getLogger()->emerg("[$key] Read " . strlen($data) . ": " . json_encode($data));
                    $output->write($data);
                    if (!$output->flush()) {
                        $this->messageBroker->getLogger()->emerg("Flush failed");
                    }
                }
            } catch (StreamException $exception) {
                $this->disconnectClient($key);
                break;
            }
        }
    }

    private function drainStreams()
    {
        foreach ($this->lingeringStreams as $key => $stream) {
            $this->drainStream($key);
        }
    }

    private function drainStream(int $key)
    {
        if (!isset($this->lingeringStreams[$key])) {
            return;
        }

        /** @var SocketStream $stream */
        $stream = $this->lingeringStreams[$key]['stream'];
        $uid = $this->lingeringStreams[$key]['key'];
        $time = $this->lingeringStreams[$key]['time'];
        $shatDown = $this->lingeringStreams[$key]['shatDown'];

        $readBytes = 0;

        try {
            if (!$shatDown) {
                $stream->shutdown(STREAM_SHUT_RD);
                $this->lingeringStreams[$key]['shatDown'] = true;
            }
            $now = time();

            if ($stream->isClosed()) {
                unset ($this->lingeringStreams[$key]);
                $this->readSelector->unregister($stream);

                return;
            }

            if ($now > $time + 5) {
                unset ($this->lingeringStreams[$key]);
                $this->readSelector->unregister($stream);
                $stream->close();
                $this->messageBroker->getLogger()->warn("Stream disconnected forcefully: $uid ($key)");

                return;
            }

            // frontend connections must be checked against POLLIN revent
            $pollin = $stream->select(0);

            if (!$pollin) {
                return;
            }

            // this will throw an exception if POLLIN and EOF occurred
            while ('' !== ($buffer = $stream->read())) {
                $readBytes += strlen($buffer);
            }

            return;
        } catch (StreamException $exception) {

        }

        if ($readBytes === 0) {
            //$this->messageBroker->getLogger()->debug("Drained stream: $uid ($key)");
            // its an EOF mark...
            unset ($this->lingeringStreams[$key]);
            $this->readSelector->unregister($stream);
            $stream->close();
        }
    }

    private function disconnectClient(int $key)
    {
        if (isset($this->frontendStreams[$key])) {
            $stream = $this->frontendStreams[$key];
            $this->lingeringStreams[] = [
                'key' => $key,
                'stream' => $stream,
                'time' => time(),
                'shatDown' => false,
            ];
            if ($stream->isReadable()) {
                if ($stream->isWritable()) {
                    $stream->flush();
                }
                $stream->shutdown(STREAM_SHUT_RD);
            }
            $this->drainStreams();
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
        $exception = null;
        try {
            if ($ipc->isReadable() && !$ipc->select(0)) {
                return;
            }

        } catch (StreamException $exception) {

        }

        try {
            $ipc->close();
        } catch (StreamException $exception) {

        }
        $this->disconnectClient($uid);

        unset ($this->workerPipe[$uid]);
        unset ($this->availableWorkers[$uid]);
        unset ($this->busyWorkers[$uid]);
    }

    private function connectToBackend(SocketStream $client)
    {
        foreach ($this->availableWorkers as $uid => $port) {
            $host = $this->workerHost;

            $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 0, STREAM_CLIENT_CONNECT, $this->streamContext);
            if (!$socket) {
                //unset($this->availableWorkers[$uid]);
                $this->checkBackendConnection($uid);

                if (isset($this->availableWorkers[$uid])) {
                    $this->messageBroker->getLogger()->err("Could not bind to worker $uid at port $port: [$errno] $errstr");
                }

                continue;
            }

            $downstream = new SocketStream($socket);
            $this->setStreamOptions($downstream);
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

    private function addBackends()
    {
        $streams = $this->readSelector->getSelectedStreams(Selector::OP_READ);
        $registeredWorkers = 0;

//        $connected = json_encode(array_keys($this->workerPipe));
//        $busy = json_encode(array_keys($this->busyWorkers));
//        $free = json_encode(array_keys($this->availableWorkers));
//        $clients = json_encode(array_keys($this->frontendStreams));
//        $this->getLogger()->debug("Already connected: " . $connected . ", busy: " . $busy . ", available: " . $free . ", clients: " . $clients);

        if (!in_array($this->registratorServer->getSocket(), $streams)) {
            return;
        }

        try {
            while ($connection = $this->registratorServer->accept()) {
                $in = false;
                $this->setStreamOptions($connection);
                if ($connection->select(100)) {
                    while (false === $in) {
                        $in = $connection->read('!');
                    }
                    list($uid, $port) = explode(":", $in);

                    $this->availableWorkers[$uid] = $port;
                    $this->workerPipe[$uid] = $connection;
                    $registeredWorkers++;

                    continue;
                }

                throw new \RuntimeException("Downstream connection is broken");
            }
        } catch (SocketTimeoutException $exception) {

        }
    }

    private function setStreamOptions(SocketStream $stream)
    {
        try {
            $stream->setOption(SO_KEEPALIVE, 1);
            $stream->setOption(TCP_NODELAY, 1);
        } catch (UnsupportedOperationException $e) {
            // this may happen in case of disabled PHP extension, or definitely happen in case of HHVM
        }
    }

    private function handleBackends()
    {
        foreach ($this->workerPipe as $uid => $ipc) {
            $this->checkBackendConnection($uid);
        }
    }

    public function getRegistratorPipe(int $workerUid, int $port)
    {
        if ($this->registratorPipe) {
            return $this->registratorPipe;
        }

        if (!$this->registratorAddress) {
            return;
        }

        $leaderPipe = @stream_socket_client('tcp://' . $this->registratorAddress, $errno, $errstr, 0, STREAM_CLIENT_CONNECT, $this->streamContext);
        if ($leaderPipe) {
            $leaderPipe = new SocketStream($leaderPipe);
            $this->setStreamOptions($leaderPipe);
            $leaderPipe->write("$workerUid:$port!");
            $leaderPipe->flush();
            $this->registratorPipe = $leaderPipe;
        } else {
            $this->messageBroker->getLogger()->err("Could not connect to leader: $errstr [$errno]");
        }

        return $this->registratorPipe;
    }
}