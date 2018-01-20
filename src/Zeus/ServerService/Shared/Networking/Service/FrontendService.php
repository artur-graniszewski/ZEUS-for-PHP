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
    const STATUS_WORKER_READY = 'ready';
    const STATUS_WORKER_BUSY = 'busy';
    const STATUS_WORKER_FAIL = 'fail';

    /** @var bool */
    private $isBusy = false;

    /** @var bool */
    private $isLeader = false;

    /** @var string */
    private $workerHost = '127.0.0.3';

    /** @var string */
    private $backendHost = '127.0.0.1';

    /** @var SocketMessageBroker */
    private $messageBroker;

    /** @var SocketStream[] */
    private $backendStreams = [];

    /** @var SocketStream[] */
    private $frontendStreams = [];

    /** @var int[] */
    private $availableWorkers = [];

    /** @var SocketServer */
    private $frontendServer;

    /** @var Selector */
    private $selector;

    /** @var SocketServer */
    private $registratorServer;

    /** @var string[] */
    private $frontendIpcAddresses = [];

    /** @var resource */
    private $streamContext;

    /** @var int */
    private $expectedFrontendWorkers = 0;

    /** @var string */
    private $lastStatus;

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
            try {
                if (!$this->isLeader) {

                    return;
                }

                if (!$this->isBusy) {
                    $event->getWorker()->setRunning();
                    $this->isBusy = true;
                }

                $this->onFrontendLoop($event);
            } catch (\Throwable $ex) {
                $this->messageBroker->getLogger()->err((string) $ex);
            }
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

        $events->attach(SchedulerEvent::EVENT_LOOP, function (SchedulerEvent $event) {
            $this->onSchedulerLoop($event);
        }, 1000);

        $events->attach(SchedulerEvent::EVENT_START, function (SchedulerEvent $event) {
            $this->onSchedulerStart($event);
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
        $frontendsAmount = 4;
        $this->messageBroker->getLogger()->debug("Electing $frontendsAmount frontend worker(s)");
        $event->getScheduler()->getIpc()->send(new FrontendElectionMessage($frontendsAmount), IpcServer::AUDIENCE_AMOUNT, $frontendsAmount);
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
//            if ($this->frontendIpcPipes) {
//
//            }

            return;
        }

        if (!$message instanceof FrontendElectionMessage) {
            return;
        }

        //$this->messageBroker->getLogger()->debug("Registering as a frontend worker");
        $this->selector = new Selector();
        $this->isLeader = true;
        $this->workerServer = null;
        $this->startFrontendServer(100);

        $event->getTarget()->send(new FrontendElectedMessage($this->registratorServer->getLocalAddress()), IpcServer::AUDIENCE_ALL);
        $event->getTarget()->send(new FrontendElectedMessage($this->registratorServer->getLocalAddress()), IpcServer::AUDIENCE_SERVER);
    }

    private function onWorkerExit(WorkerEvent $event)
    {
//        foreach ($this->frontendIpcPipes as $pipe) {
//            if ($pipe && !$pipe->isClosed()) {
//                $pipe->close();
//            }
//        }
    }

    public function addRegistratorAddress(string $address)
    {
        $this->frontendIpcAddresses[] = $address;
    }

    private function addClient()
    {
        try {
            $this->connectToBackend();
        } catch (SocketTimeoutException $exception) {
        }
    }

    private function disconnectClient(int $key)
    {
        $frontendStream = null;
        $backendStream = null;
        //$this->messageBroker->getLogger()->info("Disconnect $key");
        if (isset($this->frontendStreams[$key])) {
            //$this->messageBroker->getLogger()->info("Disconnect frontend $key");
            $frontendStream = $this->frontendStreams[$key];
            $this->selector->unregister($frontendStream);
            unset($this->frontendStreams[$key]);
        }

        if (isset($this->backendStreams[$key])) {
            //$this->messageBroker->getLogger()->info("Disconnect backend $key");
            $backendStream = $this->backendStreams[$key];
            $this->selector->unregister($backendStream);
            unset($this->backendStreams[$key]);
        }

        if ($frontendStream) {
            try {
                if ($frontendStream->isReadable()) {
                    if ($frontendStream->isWritable()) {
                        $frontendStream->flush();
                    }

                    $frontendStream->shutdown(STREAM_SHUT_RD);
                }

            } catch (\Throwable $exception) {
                $this->messageBroker->getLogger()->err($exception);
            }
            if (!$frontendStream->isClosed()) {
                $frontendStream->close();
            }
        }

        if ($backendStream) {
            try {
                if ($backendStream->isReadable()) {
                    if ($backendStream->isWritable()) {
                        $backendStream->flush();
                    }

                    $backendStream->shutdown(STREAM_SHUT_RD);
                }
            } catch (\Throwable $exception) {
                $this->messageBroker->getLogger()->err($exception);
            }

            if (!$backendStream->isClosed()) {
                $backendStream->close();
            }
        }
    }

    private function connectToBackend()
    {
        while (true) {
            list($uid, $port) = $this->getBackendWorker();
            if ($uid == 0) {
                //$this->messageBroker->getLogger()->err("Waiting for backend worker");
                return;
            }
            $host = $this->workerHost;

            $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 100, STREAM_CLIENT_CONNECT, $this->streamContext);
            if (!$socket) {
                $this->sendStatusToFrontend($uid, $port, self::STATUS_WORKER_FAIL);
                $this->messageBroker->getLogger()->err("Connection refused: no backend servers available");
                continue;
            }

            //$this->messageBroker->getLogger()->debug(getmypid() . " connecting to worker $uid");
            $client = $this->getFrontendServer()->accept();
            $client->setBlocking(true);
            $this->setStreamOptions($client);

            $backend = new SocketStream($socket);
            $this->setStreamOptions($backend);
            $backend->setBlocking(true);
            $this->backendStreams[$uid] = $backend;
            $this->frontendStreams[$uid] = $client;

            $backendKey = $backend->register($this->selector, Selector::OP_READ);
            $frontendKey = $client->register($this->selector, Selector::OP_READ);
            $fromFrontendBuffer = new StreamTunnel($frontendKey, $backendKey);
            $fromBackendBuffer = new StreamTunnel($backendKey, $frontendKey);
            $fromBackendBuffer->setId($uid);
            $fromFrontendBuffer->setId($uid);
            $backendKey->attach($fromBackendBuffer);
            $frontendKey->attach($fromFrontendBuffer);

            return;
        }
    }

    private function onFrontendLoop(WorkerEvent $event)
    {
        static $last = 0;
        $now = microtime(true);
        do {
            if ($now - $last >1) {
                //$this->messageBroker->getLogger()->err("Leader loop");
            }
            if (!$this->selector->select(1234)) {
                if ($now - $last >1) {
                    $last = $now;
                }
                continue;
            }

            if ($now - $last >1) {
                $last = $now;
            }

            $t1 = microtime(true);
            $keys = $this->selector->getSelectionKeys();
            $t2 = microtime(true);

            $uidsToIgnore = [];
            foreach ($keys as $index => $selectionKey) {
                if (!$selectionKey->isAcceptable()) {
                    $t3 = microtime(true);
                    /** @var StreamTunnel $buffer */
                    $buffer = $selectionKey->getAttachment();
                    $uid = $buffer->getId();

                    if (in_array($uid, $uidsToIgnore)) {
                        continue;
                    }

                    try {
                        $buffer->tunnel();
                    } catch (\Throwable $exception) {
                        /** @var SocketStream $input */
                        $this->disconnectClient($uid);
                        $uidsToIgnore[] = $uid;
                        //$this->messageBroker->getLogger()->err("Disconnect successful: ($uid) " . $selectionKey->getStream()->getRemoteAddress());
                        //$this->messageBroker->getLogger()->err("$exception");
                        continue;// disconnect may have altered other streams in selector, reset it
                    }
                    $t4 = microtime(true);
                    //trigger_error(sprintf("LOOP STEP DONE IN T1: %5f, T2: %5f", $t2 - $t1, $t4 - $t3));
                    continue;
                }

                $stream = $selectionKey->getStream();
                $resourceId = $stream->getResourceId();

                if ($resourceId === $this->frontendServer->getSocket()->getResourceId()) {
                    $this->addClient();
                    continue;
                }

                $this->messageBroker->getLogger()->err("Unknown resource id selected");
            }
        } while ((microtime(true) - $now < 1));
        //} while (false);
    }

    private function addBackend()
    {
        try {
            static $lasttime;
            $now = time();
            if ($lasttime !== $now) {
                $lasttime = $now;
                $uids = array_keys($this->availableWorkers);
                sort($uids);
                //$this->messageBroker->getLogger()->debug("Available backend workers: " . json_encode($uids));
            }

            $connection = true;
            $limit = 10;
            while ($connection && $limit > 0) {
                $connection = $this->registratorServer->accept();
                $this->setStreamOptions($connection);
                if ($connection->select(5)) {
                    $this->checkBackendStatus($connection);
                }
                $limit --;
            }
            return;
        } catch (SocketTimeoutException $exception) {

        }
    }

    private function checkBackendStatus(SocketStream $stream)
    {
        $in = false;
        while (false === $in) {
            $in = $stream->read('!');
        }
        list($status, $uid, $port) = explode(":", $in);

        switch ($status) {
            case (self::STATUS_WORKER_READY):
                $this->availableWorkers[$uid] = $port;
                //$this->messageBroker->getLogger()->debug("Worker $uid marked as ready");
                break;
            case (self::STATUS_WORKER_BUSY):
                unset ($this->availableWorkers[$uid]);
                //$this->messageBroker->getLogger()->debug("Worker $uid marked as busy");
                break;

            case 'lock':
                $frontendUid = $uid;
                $uid = key($this->availableWorkers);
                $port = current($this->availableWorkers);
                if (!$uid) {
                    //$this->messageBroker->getLogger()->alert("No available backend workers: " . count($this->availableWorkers));
                    $stream->write("0:0@");
                } else {
                    unset ($this->availableWorkers[$uid]);
                    //$this->messageBroker->getLogger()->debug("Worker $uid at $port locked for frontend $frontendUid");
                    $stream->write("$uid:$port@");
                }
                break;

            case (self::STATUS_WORKER_FAIL):
                unset ($this->availableWorkers[$uid]);
                $this->messageBroker->getLogger()->err("Worker $uid marked as failed");
                break;

            default:
                $this->messageBroker->getLogger()->err("Unsupported status [$status] of a worker $uid");
                break;
        }

        $stream->close();
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

    public function sendStatusToFrontend(int $workerUid, int $port, string $status) : bool
    {
        if (!$this->frontendIpcAddresses || count($this->frontendIpcAddresses) < $this->expectedFrontendWorkers) {
            return false;
        }

        if ($this->lastStatus !== $status) {
            //$this->lastStatus = $status;
        } else {
            return true;
        }

        //$this->messageBroker->getLogger()->debug("Sending $status");
        foreach ($this->frontendIpcAddresses as $id => $address) {
            $leaderPipe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 1000, STREAM_CLIENT_CONNECT, $this->streamContext);
            if ($leaderPipe) {
                $leaderPipe = new SocketStream($leaderPipe);
                $this->setStreamOptions($leaderPipe);
                $leaderPipe->write("$status:$workerUid:$port!");
                while(!$leaderPipe->flush()) {

                }
                //$this->frontendIpcPipes[$id] = $leaderPipe;
                $leaderPipe->close();
                break;
                //$this->messageBroker->getLogger()->debug("Attaching to frontend worker on address $address");
            } else {
                $this->messageBroker->getLogger()->err("Could not connect to leader: $errstr [$errno]");
            }
        }

        return true;
    }

    private function getBackendWorker() : array
    {
        foreach ($this->frontendIpcAddresses as $id => $address) {
            $leaderPipe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 1000, STREAM_CLIENT_CONNECT, $this->streamContext);
            if (!$leaderPipe) {
                $this->messageBroker->getLogger()->err("Could not connect to leader at $address: $errstr [$errno]");

                return [0, 0];
            }

            $leaderPipe = new SocketStream($leaderPipe);
            $this->setStreamOptions($leaderPipe);
            $uid = getmypid();
            $leaderPipe->write("lock:$uid:1!");
            while (!$leaderPipe->flush()) {
                //$this->messageBroker->getLogger()->debug("flushing...");
            };

            $timeout = 10;
            while (!$leaderPipe->select(1000) || '' === ($status = $leaderPipe->read("@"))) {
                // @todo: this happens too often, verify networking code, etc...
                //$this->messageBroker->getLogger()->debug("reading...");
                $timeout--;
                if ($timeout < 0) {
                    $this->messageBroker->getLogger()->err("Unable to lock the backend server, received: " . $leaderPipe->read());
                    continue;
                }
            }
            list($uid, $port) = explode(":", $status);
            $leaderPipe->close();
            //$this->messageBroker->getLogger()->debug("Attaching to frontend worker on address $address");
            return [(int) $uid, (int) $port];
        }
    }

    private function onSchedulerLoop(SchedulerEvent $event)
    {
        $this->addBackend();
    }

    private function onSchedulerStart(SchedulerEvent $event)
    {
        $this->startRegistratorServer();
    }

    private function startRegistratorServer()
    {
        $server = new SocketServer();
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->backendHost, 1000, 0);
//        $server->register($this->selector, Selector::OP_ACCEPT);
        $this->registratorServer = $server;
    }
}