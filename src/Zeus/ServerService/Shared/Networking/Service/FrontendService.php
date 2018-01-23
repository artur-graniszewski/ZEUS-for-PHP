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
    private $frontendSelector;

    /** @var SocketServer */
    private $registratorServer;

    /** @var SocketStream[] */
    private $registeredWorkerStreams = [];

    /** @var string */
    private $backendRegistrator = '';

    /** @var resource */
    private $streamContext;

    /** @var string */
    private $lastStatus;

    /** @var  SocketStream */
    private $leaderPipe;

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
            $this->onFrontendElected($event);
        }, WorkerEvent::PRIORITY_FINALIZE);

        $events->attach(WorkerEvent::EVENT_CREATE, function (WorkerEvent $event) {
            $this->onWorkerCreate($event);
        }, 1000);

        $events->attach(WorkerEvent::EVENT_EXIT, function (WorkerEvent $event) {
            if ($this->leaderPipe) {
                $this->leaderPipe->flush();
                $this->leaderPipe->shutdown(STREAM_SHUT_RD);
                $this->leaderPipe->close();
            }
        }, 1000);

        $events->attach(SchedulerEvent::EVENT_LOOP, function (SchedulerEvent $event) use ($events) {
            static $lasttime;
            $now = time();
            if ($lasttime !== $now) {
                $lasttime = $now;
                $uids = array_keys($this->availableWorkers);
                sort($uids);
                //$this->messageBroker->getLogger()->debug("Available backend workers: " . json_encode($uids));
            }
        }, 1000);
        $events->attach(SchedulerEvent::EVENT_START, function (SchedulerEvent $event) use ($events) {
            $this->startRegistratorServer();
            $events->getSharedManager()->attach('*', IpcEvent::EVENT_HANDLING_MESSAGES, function($e) { $this->onIpcSelect($e); }, -9000);
            $events->getSharedManager()->attach('*', IpcEvent::EVENT_STREAM_READABLE, function($e) { $this->checkWorkerOutput($e); }, -9000);
        }, 1000);
    }

    private function onIpcSelect(IpcEvent $event)
    {
        /** @var Selector $selector */
        $selector = $event->getParam('selector');
        $selector->register($this->registratorServer->getSocket(), Selector::OP_ACCEPT);
    }

    private function checkWorkerOutput(IpcEvent $event)
    {
        /** @var SocketStream $stream */
        $stream = $event->getParam('stream');

        /** @var Selector $selector */
        $selector = $event->getParam('selector');

        if ($stream === $this->registratorServer->getSocket()) {
            $this->addBackend($selector);
            return;
        }

        if (!in_array($stream, $this->registeredWorkerStreams)) {
            return;
        }

        $this->checkBackendStatus($event->getParam('selectionKey'));
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
        if ($this->backendRegistrator) {
            $event->setParam('leaderIpcAddress', $this->backendRegistrator);
        }
    }

    public function onWorkerInit(WorkerEvent $event)
    {
        if ($event->getParam('leaderIpcAddress')) {
            $this->backendRegistrator = $event->getParam('leaderIpcAddress');
        }
    }

    private function startLeaderElection(SchedulerEvent $event)
    {
        $this->startRegistratorServer();
        $frontendsAmount = (int) max(1, \Zeus\Kernel\System\Runtime::getNumberOfProcessors() / 2);
        $frontendsAmount = 4;
        $this->messageBroker->getLogger()->debug("Electing $frontendsAmount frontend worker(s)");
        $event->getScheduler()->getIpc()->send(new FrontendElectionMessage($this->registratorServer->getLocalAddress(), $frontendsAmount), IpcServer::AUDIENCE_AMOUNT, $frontendsAmount);
    }

    private function startFrontendServer(int $backlog)
    {
        $server = new SocketServer();
        $server->setReuseAddress(true);
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->messageBroker->getConfig()->getListenAddress(), $backlog, $this->messageBroker->getConfig()->getListenPort());
        $server->register($this->frontendSelector, Selector::OP_ACCEPT);
        $this->frontendServer = $server;
    }

    private function onFrontendElected(IpcEvent $event)
    {
        $message = $event->getParams();

        if ($message instanceof FrontendElectedMessage && !$this->isLeader) {
            /** @var FrontendElectedMessage $message */
            $this->setRegistratorAddress($message->getIpcAddress());

            return;
        }

        if (!$message instanceof FrontendElectionMessage) {
            return;
        }

        $this->messageBroker->getLogger()->debug("Registering as a frontend worker");
        $this->frontendSelector = new Selector();
        $this->isLeader = true;
        $this->workerServer = null;
        $this->startFrontendServer(100);
        $this->setRegistratorAddress($message->getIpcAddress());

        $event->getTarget()->send(new FrontendElectedMessage($this->registratorServer->getLocalAddress()), IpcServer::AUDIENCE_ALL);
        $event->getTarget()->send(new FrontendElectedMessage($this->registratorServer->getLocalAddress()), IpcServer::AUDIENCE_SERVER);
    }

    public function setRegistratorAddress(string $address)
    {
        $this->backendRegistrator = $address;
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
        if (isset($this->frontendStreams[$key])) {
            $frontendStream = $this->frontendStreams[$key];
            $this->frontendSelector->unregister($frontendStream);
            unset($this->frontendStreams[$key]);
        }

        if (isset($this->backendStreams[$key])) {
            $backendStream = $this->backendStreams[$key];
            $this->frontendSelector->unregister($backendStream);
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
                $this->messageBroker->getLogger()->warn("Waiting for availability of a backend worker");
                return;
            }
            $host = $this->workerHost;

            $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $this->streamContext);
            if (!$socket) {
                $this->sendStatusToFrontend($uid, $port, self::STATUS_WORKER_FAIL);
                $this->messageBroker->getLogger()->err("Connection refused: backend worker $uid failed");
                continue;
            }

            $client = $this->getFrontendServer()->accept();
            $this->setStreamOptions($client);

            $backend = new SocketStream($socket);
            $this->setStreamOptions($backend);
            $backend->setBlocking(true);
            $this->backendStreams[$uid] = $backend;
            $this->frontendStreams[$uid] = $client;

            $backendKey = $backend->register($this->frontendSelector, Selector::OP_READ);
            $frontendKey = $client->register($this->frontendSelector, Selector::OP_READ);
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
            if (!$this->frontendSelector->select(1234)) {
                if ($now - $last >1) {
                    $last = $now;
                }
                continue;
            }

            if ($now - $last >1) {
                $last = $now;
            }

            $t1 = microtime(true);
            $keys = $this->frontendSelector->getSelectionKeys();
            $t2 = microtime(true);

            $uidsToIgnore = [];
            foreach ($keys as $index => $selectionKey) {
                if ($selectionKey->isAcceptable()) {
                    $stream = $selectionKey->getStream();
                    $resourceId = $stream->getResourceId();

                    if ($resourceId === $this->frontendServer->getSocket()->getResourceId()) {
                        $this->addClient();
                        continue;
                    }

                    $this->messageBroker->getLogger()->err("Unknown resource id selected");
                }

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
                    return;// disconnect may have altered other streams in selector, reset it
                }
                $t4 = microtime(true);
                //trigger_error(sprintf("LOOP STEP DONE IN T1: %5f, T2: %5f", $t2 - $t1, $t4 - $t3));
                continue;
            }
        } while ((microtime(true) - $now < 1));
        //} while (false);
    }

    private function addBackend(Selector $selector)
    {
        try {
            $connection = $this->registratorServer->accept();
            $this->setStreamOptions($connection);
            $this->registeredWorkerStreams[] = $connection;
            $selectionKey = $selector->register($connection, Selector::OP_READ);
            $selectionKey->attach(new ReadBuffer());
            $this->setStreamOptions($connection);
        } catch (SocketTimeoutException $exception) {

        }
    }

    private function checkBackendStatus(SelectionKey $selectionKey)
    {
        $stream = $selectionKey->getStream();
        $data = $stream->read();
        $key = array_search($stream, $this->registeredWorkerStreams);
        /** @var ReadBuffer $buffer */
        $buffer = $selectionKey->getAttachment();

        if ($data === '') {
            unset ($this->registeredWorkerStreams[$key]);
            $selectionKey->cancel();
            $stream->flush();
            $stream->shutdown(STREAM_SHUT_RD);
            $stream->close();

            return;
        }

        $buffer->append($data);

        if ($buffer->find('!') < 0) {
            return;
        }

        list($status, $uid, $port) = explode(":", $buffer->getData());

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

        do {
            $done = $stream->flush();
        } while (!$done);
        //$stream->close();
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
        if (!$this->backendRegistrator) {
            return false;
        }

        if ($this->lastStatus !== $status) {
            //$this->lastStatus = $status;
        } else {
            return true;
        }

        $address = $this->backendRegistrator;

        if (!$this->leaderPipe) {
            $leaderPipe = @stream_socket_client('tcp://' . $address, $errno, $errstr, 1000, STREAM_CLIENT_CONNECT, $this->streamContext);
            if (!$leaderPipe) {
                $this->messageBroker->getLogger()->err("Could not connect to leader: $errstr [$errno]");
                return false;
            }
            $leaderPipe = new SocketStream($leaderPipe);
            $this->setStreamOptions($leaderPipe);
            $this->leaderPipe = $leaderPipe;
        } else {
            $leaderPipe = $this->leaderPipe;
        }

        $leaderPipe->write("$status:$workerUid:$port!");
        while(!$leaderPipe->flush()) {

        }
        return true;
    }

    private function getBackendWorker() : array
    {
        if (!$this->leaderPipe) {
            $leaderPipe = @stream_socket_client('tcp://' . $this->backendRegistrator, $errno, $errstr, 3, STREAM_CLIENT_CONNECT, $this->streamContext);
            if (!$leaderPipe) {
                $this->messageBroker->getLogger()->err("Could not connect to registrator: $errstr [$errno]");
                return [0, 0];
            }
            $leaderPipe = new SocketStream($leaderPipe);
            $this->setStreamOptions($leaderPipe);
            $this->leaderPipe = $leaderPipe;
        } else {
            $leaderPipe = $this->leaderPipe;
        }

        $uid = getmypid();
        $leaderPipe->write("lock:$uid:1!");
        while (!$leaderPipe->flush()) {
            //$this->messageBroker->getLogger()->debug("flushing...");
        };

        $status = '';
        $timeout = 10;
        while (substr($status, -1) !== '@') {
            if ($leaderPipe->select(100)) {
                $buffer = $leaderPipe->read();

                if ('' === $buffer) {
                    // EOF
                    $this->messageBroker->getLogger()->err("Unable to lock the backend worker: connection broken, read [$status]");
                    return [0, 0];
                }

                $status .= $buffer;
            }

            $timeout--;
            if ($timeout < 0) {
                $this->messageBroker->getLogger()->err("Unable to lock the backend worker: timeout detected, read: [$status]");
                return [0, 0];
            }
        }
        list($uid, $port) = explode(":", $status);

        return [(int) $uid, (int) $port];
    }

    private function startRegistratorServer()
    {
        $server = new SocketServer();
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->backendHost, 1000, 0);
        $this->registratorServer = $server;
    }
}