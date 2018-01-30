<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\IO\Exception\SocketTimeoutException;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;

use function microtime;
use function stream_socket_client;
use function count;
use function max;
use function in_array;
use function array_search;
use function stream_context_create;
use function explode;
use Zeus\ServerService\Shared\Networking\Message\FrontendElectionMessage;
use Zeus\ServerService\Shared\Networking\SocketMessageBroker;

class FrontendService
{
    private $uid = 0;

    /** @var bool */
    private $isBusy = false;

    /** @var bool */
    private $isFrontend = false;

    /** @var string */
    private $workerHost = '127.0.0.3';

    /** @var SocketMessageBroker */
    private $messageBroker;

    /** @var SocketStream[] */
    private $backendStreams = [];

    /** @var SocketStream[] */
    private $frontendStreams = [];

    /** @var SocketServer */
    private $frontendServer;

    /** @var Selector */
    private $frontendSelector;

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
            $this->uid = $event->getWorker()->getUid();
        }, WorkerEvent::PRIORITY_REGULAR);

        $events->attach(WorkerEvent::EVENT_LOOP, function (WorkerEvent $event) {
            try {
                if (!$this->isFrontend) {

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


        $events->getSharedManager()->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, function (IpcEvent $event) {
            $this->onFrontendElected($event);
        }, WorkerEvent::PRIORITY_FINALIZE);

        $events->attach(SchedulerEvent::EVENT_START, function (SchedulerEvent $event) use ($events) {
            $this->startFrontendElection($event);
        }, 1000);
    }

    private function startFrontendElection(SchedulerEvent $event)
    {
        $frontendsAmount = (int) max(1, \Zeus\Kernel\System\Runtime::getNumberOfProcessors() / 2);
        $this->messageBroker->getLogger()->debug("Electing $frontendsAmount frontend worker(s)");
        $event->getScheduler()->getIpc()->send(new FrontendElectionMessage($frontendsAmount), IpcServer::AUDIENCE_AMOUNT, $frontendsAmount);
    }

    public function getFrontendServer() : SocketServer
    {
        if (!$this->frontendServer) {
            throw new \LogicException("Frontend server not available");
        }

        return $this->frontendServer;
    }

    private function startFrontendServer(int $backlog)
    {
        $server = new SocketServer();
        $server->setReuseAddress(true);
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($this->messageBroker->getConfig()->getListenAddress(), $backlog, $this->messageBroker->getConfig()->getListenPort());
        $server->register($this->frontendSelector, SelectionKey::OP_ACCEPT);
        $this->frontendServer = $server;
    }

    private function onFrontendElected(IpcEvent $event)
    {
        $message = $event->getParams();

        if (!$message instanceof FrontendElectionMessage) {
            return;
        }

        $this->messageBroker->getLogger()->debug("Becoming frontend worker");
        $this->messageBroker->getRegistrator()->notifyRegistrator($this->uid, 0, RegistratorService::STATUS_WORKER_GONE);
        $this->frontendSelector = new Selector();
        $this->isFrontend = true;
        $this->workerServer = null;
        $this->startFrontendServer(100);
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
        $registrator = $this->messageBroker->getRegistrator();
        while (true) {
            $t1 = microtime(true);
            list($uid, $port) = $registrator->getBackendWorker();
            $t2 = microtime(true);
            //$this->messageBroker->getLogger()->debug("Worker locked in " . sprintf("%5f", $t2 - $t1));
            if ($uid == 0) {
                $this->messageBroker->getLogger()->warn("Waiting for availability of a backend worker");
                return;
            }
            $host = $this->workerHost;

            $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $this->streamContext);
            if (!$socket) {
                $registrator->notifyRegistrator($uid, $port, RegistratorService::STATUS_WORKER_FAILED);
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

            $backendKey = $backend->register($this->frontendSelector, SelectionKey::OP_READ);
            $frontendKey = $client->register($this->frontendSelector, SelectionKey::OP_READ);
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
            }
        } while (microtime(true) - $now < 1);
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
}