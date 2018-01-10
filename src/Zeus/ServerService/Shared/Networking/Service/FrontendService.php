<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Networking\Exception\SocketTimeoutException;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\Networking\SocketServer;
use Zeus\Networking\Stream\SelectionKey;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;

use function microtime;
use function stream_socket_client;
use function count;
use function in_array;
use function array_search;
use function explode;
use Zeus\ServerService\Shared\Networking\Message\FrontendElectionMessage;
use Zeus\ServerService\Shared\Networking\Message\FrontendElectedMessage;
use Zeus\ServerService\Shared\Networking\SocketMessageBroker;

class FrontendService
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
    private $backendStreams = [];

    /** @var int[] */
    private $availableWorkers = [];

    /** @var int[] */
    private $busyWorkers = [];

    /** @var SocketStream[] */
    private $frontendStreams = [];

    /** @var SocketServer */
    private $frontendServer;

    /** @var string */
    private $backendHost = '127.0.0.1';

    /** @var Selector */
    private $selector;

    /** @var SocketServer */
    private $registratorServer;

    /** @var string[] */
    private $frontendIpcAddresses = [];

    /** @var SocketStream[] */
    private $frontendIpcPipes = [];

    /** @var resource */
    private $streamContext;

    /** @var int */
    private $expectedFrontendWorkers;

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
        $events->attach(WorkerEvent::EVENT_INIT, function (WorkerEvent $event) {
            $this->onWorkerInit($event);
        }, WorkerEvent::PRIORITY_REGULAR + 1);

        $events->attach(WorkerEvent::EVENT_LOOP, function (WorkerEvent $event) {
            if (!$this->isLeader) {

                return;
            }

            if (!$this->isBusy) {
                $event->getWorker()->setRunning();
                $this->isBusy = true;
            }

            $this->onLeaderLoop($event);
        }, WorkerEvent::PRIORITY_REGULAR);

        $events->attach(SchedulerEvent::EVENT_START, function (SchedulerEvent $event) {
            $this->startLeaderElection($event);
        }, SchedulerEvent::PRIORITY_FINALIZE + 1);

        $events->getSharedManager()->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, function (IpcEvent $event) {
            $this->onLeaderElection($event);
        }, WorkerEvent::PRIORITY_FINALIZE);

        $events->attach(WorkerEvent::EVENT_CREATE, function (WorkerEvent $event) {
            $this->onWorkerCreate($event);
        }, 1000);

        $events->attach(WorkerEvent::EVENT_EXIT, function (WorkerEvent $event) {
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
        if ($this->frontendIpcAddresses) {
            $event->setParam('leaderIpcAddress', $this->frontendIpcAddresses);
        }
    }

    public function onWorkerInit(WorkerEvent $event)
    {
        if ($event->getParam('leaderIpcAddress')) {
            $this->frontendIpcAddresses = $event->getParam('leaderIpcAddress');
        }
    }

    private function startLeaderElection(SchedulerEvent $event)
    {
        $frontendsAmount = (int) max(1, \Zeus\Kernel\System\Runtime::getNumberOfProcessors() / 2);
        //$frontendsAmount = 1;
        $this->messageBroker->getLogger()->debug("Electing $frontendsAmount frontend worker(s)");
        $event->getScheduler()->getIpc()->send(new FrontendElectionMessage($frontendsAmount), IpcServer::AUDIENCE_AMOUNT, $frontendsAmount);
    }

    private function startRegistratorServer()
    {
        $server = new SocketServer();
        $server->setReuseAddress(true);
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->backendHost, 100, 0);
        $server->register($this->selector, Selector::OP_ACCEPT);
        $this->registratorServer = $server;
    }

    private function startFrontendServer(int $backlog)
    {
        $server = new SocketServer();
        $server->setReuseAddress(true);
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->messageBroker->getConfig()->getListenAddress(), $backlog, $this->messageBroker->getConfig()->getListenPort());
        $server->register($this->selector, Selector::OP_ACCEPT);
        $this->frontendServer = $server;
    }

    private function onLeaderElection(IpcEvent $event)
    {
        $message = $event->getParams();

        if ($message instanceof FrontendElectedMessage) {
            /** @var FrontendElectedMessage $message */
            $this->addRegistratorAddress($message->getIpcAddress());
            if ($this->frontendIpcPipes) {

            }

            return;
        }


        if (!$message instanceof FrontendElectionMessage) {
            return;
        }

        $this->messageBroker->getLogger()->debug("Becoming frontend worker");
        $this->selector = new Selector();
        $this->startRegistratorServer();
        $this->isLeader = true;
        $this->workerServer = null;
        $this->startFrontendServer(100);

        $this->messageBroker->getLogger()->debug("Asking backend workers to register themselves");
        $event->getTarget()->send(new FrontendElectedMessage($this->registratorServer->getLocalAddress()), IpcServer::AUDIENCE_ALL);
        $event->getTarget()->send(new FrontendElectedMessage($this->registratorServer->getLocalAddress()), IpcServer::AUDIENCE_SERVER);
    }

    private function onWorkerExit(WorkerEvent $event)
    {
        foreach ($this->frontendIpcPipes as $pipe) {
            if ($pipe && !$pipe->isClosed()) {
                $pipe->close();
            }
        }
    }

    public function addRegistratorAddress(string $address)
    {
        $this->frontendIpcAddresses[] = $address;
    }

    private function addClient()
    {
        try {
            if ($this->availableWorkers) {
                $this->connectToBackend();
            }
        } catch (SocketTimeoutException $exception) {
        }
    }

    private function handleClient(SelectionKey $selectionKey)
    {
        /** @var StreamBuffer $buffer */
        $buffer = $selectionKey->getAttachment();

        try {
            $buffer->tunnel();
        } catch (\Exception $exception) {
            /** @var SocketStream $input */

            $this->disconnectClient($buffer->getId());
        }
    }

    private function disconnectClient(int $key)
    {
        if (isset($this->frontendStreams[$key])) {
            $stream = $this->frontendStreams[$key];
            $this->selector->unregister($stream);
            if ($stream->isReadable()) {
                if ($stream->isWritable()) {
                    $stream->flush();
                }

                $stream->shutdown(STREAM_SHUT_RD);
            }

            if (!$stream->isClosed()) {
                $stream->close();
            }
            unset($this->frontendStreams[$key]);
        }

        if (isset($this->backendStreams[$key])) {
            $stream = $this->backendStreams[$key];
            $this->selector->unregister($stream);
            if ($stream->isReadable()) {
                if ($stream->isWritable()) {
                    $stream->flush();
                }

                $stream->shutdown(STREAM_SHUT_RD);
            }

            if (!$stream->isClosed()) {
                $stream->close();
            }

            unset($this->backendStreams[$key]);
            $this->availableWorkers[$key] = $this->busyWorkers[$key];
            unset($this->busyWorkers[$key]);
        }
    }

    private function connectToBackend()
    {
        foreach ($this->availableWorkers as $uid => $port) {
            $host = $this->workerHost;

            $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 0, STREAM_CLIENT_CONNECT, $this->streamContext);
            if (!$socket) {
                continue;
            }

            $client = $this->getFrontendServer()->accept();
            $client->setBlocking(false);
            //$this->messageBroker->getLogger()->err("Connected");
            $this->setStreamOptions($client);

            $backend = new SocketStream($socket);
            $this->setStreamOptions($backend);
            $backend->setBlocking(true);
            $this->backendStreams[$uid] = $backend;
            $this->busyWorkers[$uid] = $port;
            unset($this->availableWorkers[$uid]);
            $this->frontendStreams[$uid] = $client;
            $backendKey = $backend->register($this->selector, Selector::OP_READ);
            //$backendKey = $backend->register($this->selector, Selector::OP_READ|Selector::OP_WRITE);
            $frontendKey = $client->register($this->selector, Selector::OP_READ);
            //$frontendKey = $client->register($this->selector, Selector::OP_READ|Selector::OP_WRITE);
            $fromFrontendBuffer = new StreamBuffer($frontendKey, $backendKey);
            $fromBackendBuffer = new StreamBuffer($backendKey, $frontendKey);
            $fromBackendBuffer->setId($uid);
            $fromFrontendBuffer->setId($uid);
            $backendKey->attach($fromBackendBuffer);
            $frontendKey->attach($fromFrontendBuffer);

            return;
        }

        $this->messageBroker->getLogger()->err("Connection refused: no backend servers available");
    }

    private function onLeaderLoop(WorkerEvent $event)
    {
        $now = microtime(true);
        do {
            if (!$this->selector->select(1000)) {
                continue;
            }

            $t1 = microtime(true);
            $keys = $this->selector->getSelectionKeys();
            $t2 = microtime(true);

            foreach ($keys as $index => $selectionKey) {
                if (!$selectionKey->isAcceptable()) {
                    $t3 = microtime(true);
                    $this->handleClient($selectionKey);
                    $t4 = microtime(true);
                    //trigger_error(sprintf("LOOP STEP DONE IN T1: %5f, T2: %5f", $t2 - $t1, $t4 - $t3));
                    continue;
                }

                $stream = $selectionKey->getStream();
                $resourceId = $stream->getResourceId();
                if ($resourceId === $this->registratorServer->getSocket()->getResourceId()) {
                    $this->addBackend();
                }

                if ($resourceId === $this->frontendServer->getSocket()->getResourceId()) {
                    $this->addClient();
                }
            }



        } while ((microtime(true) - $now < 1));
    }

    private function addBackend()
    {
        try {
            $connection = $this->registratorServer->accept();
            $in = false;
            $this->setStreamOptions($connection);
            if ($connection->select(100)) {
                while (false === $in) {
                    $in = $connection->read('!');
                }
                list($uid, $port) = explode(":", $in);

                $this->availableWorkers[$uid] = $port;

                return;
            }

        } catch (SocketTimeoutException $exception) {

        }

        throw new \RuntimeException("Downstream connection is broken");
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

    public function getFrontendIpcPipes(int $workerUid, int $port)
    {
        if (count($this->frontendIpcPipes) === count($this->frontendIpcAddresses)) {
            return $this->frontendIpcPipes;
        }

        if (!$this->frontendIpcAddresses || count($this->frontendIpcAddresses) < $this->expectedFrontendWorkers) {
            return;
        }

        foreach ($this->frontendIpcAddresses as $id => $address) {
            if (isset($this->frontendIpcPipes[$id])) {
                continue;
            }
            $leaderPipe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0, STREAM_CLIENT_CONNECT, $this->streamContext);
            if ($leaderPipe) {
                $leaderPipe = new SocketStream($leaderPipe);
                $this->setStreamOptions($leaderPipe);
                $leaderPipe->write("$workerUid:$port!");
                $leaderPipe->flush();
                $this->frontendIpcPipes[$id] = $leaderPipe;
                $this->messageBroker->getLogger()->debug("Attaching to frontend worker on address $address");
            } else {
                $this->messageBroker->getLogger()->err("Could not connect to leader: $errstr [$errno]");
            }
        }

        return $this->frontendIpcPipes;
    }
}