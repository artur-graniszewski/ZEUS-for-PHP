<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use LogicException;
use Throwable;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerAwareTrait;
use Zeus\IO\Exception\EOFException;
use Zeus\IO\Exception\IOException;
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
use Zeus\Kernel\System\Runtime;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;
use Zeus\ServerService\Shared\Networking\Message\FrontendElectionMessage;

use function microtime;
use function stream_socket_client;
use function max;
use function in_array;
use function defined;
use function stream_context_create;

class FrontendService
{
    use LoggerAwareTrait;

    private $uid = 0;

    /** @var bool */
    private $isBusy = false;

    /** @var bool */
    private $isFrontend = false;

    /** @var string */
    private $workerHost = '127.0.0.3';

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

    /** @var RegistratorService */
    private $registrator;

    /** @var AbstractNetworkServiceConfig */
    private $config;

    public function __construct(RegistratorService $registrator, AbstractNetworkServiceConfig $config)
    {
        $this->config = $config;
        $this->registrator = $registrator;
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

                static $last = 0;
                $now = microtime(true);
                do {
                    if ($now - $last >1) {
                        $last = $now;
                    }

                    $this->selectStreams();
                } while (microtime(true) - $now < 1);

            } catch (IOException $ex) {
                $this->getLogger()->err((string) $ex);
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
        $config = $this->config;
        $this->getLogger()->info(sprintf('Launching server on %s%s', $config->getListenAddress(), $config->getListenPort() ? ':' . $config->getListenPort(): ''));
        $cpus = Runtime::getNumberOfProcessors();
        if (defined("HHVM_VERSION")) {
            // HHVM does not support SO_REUSEADDR ?
            $frontendsAmount = 1;
            $this->getLogger()->warn("Running single frontend service due to the lack of SO_REUSEADDR option in HHVM");
        } else {
            $frontendsAmount = (int) max(1, $cpus / 2);
        }
        $this->getLogger()->debug("Detected $cpus CPUs: electing $frontendsAmount concurrent frontend worker(s)");
        $event->getScheduler()->getIpc()->send(new FrontendElectionMessage($frontendsAmount), IpcServer::AUDIENCE_AMOUNT, $frontendsAmount);
    }

    public function getFrontendServer() : SocketServer
    {
        if (!$this->frontendServer) {
            throw new LogicException("Frontend server not available");
        }

        return $this->frontendServer;
    }

    private function startFrontendServer(int $backlog)
    {
        $config = $this->config;
        $server = new SocketServer();
        try {
            $server->setReuseAddress(true);
        } catch (UnsupportedOperationException $exception) {
            $this->getLogger()->warn("Reuse address feature for Socket Streams is unsupported");
        }
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);
        $server->bind($config->getListenAddress(), $backlog, $config->getListenPort());
        $server->register($this->frontendSelector, SelectionKey::OP_ACCEPT);
        $this->frontendServer = $server;
    }

    private function onFrontendElected(IpcEvent $event)
    {
        $message = $event->getParams();

        if (!$message instanceof FrontendElectionMessage) {
            return;
        }

        $this->getLogger()->debug("Becoming frontend worker");
        $this->registrator->notifyRegistrator($this->uid, 0, RegistratorService::STATUS_WORKER_GONE);
        $this->frontendSelector = new Selector();
        $this->isFrontend = true;
        $this->workerServer = null;
        $this->startFrontendServer(100);
    }

    private function addClient()
    {
        try {
            do {
                $success = $this->connectToBackend();
            } while (!$success);
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

            } catch (Throwable $exception) {
                $this->getLogger()->err($exception);
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
            } catch (Throwable $exception) {
                $this->getLogger()->err($exception);
            }

            if (!$backendStream->isClosed()) {
                $backendStream->close();
            }
        }
    }

    private function connectToBackend() : bool
    {
        $registrator = $this->registrator;
        list($uid, $port) = $registrator->getBackendWorker();
        if ($uid == 0) {
            $this->getLogger()->warn("Waiting for availability of a backend worker");
            return true;
        }
        $host = $this->workerHost;

        $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $this->streamContext);
        if (!$socket) {
            $registrator->notifyRegistrator($uid, $port, RegistratorService::STATUS_WORKER_FAILED);
            $this->getLogger()->err("Connection refused: backend worker $uid failed");
            return false;
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

        return true;
    }

    private function selectStreams()
    {
        if (!$this->frontendSelector->select(1234)) {
            return;
        }

        $keys = $this->frontendSelector->getSelectionKeys();

        $uidsToIgnore = [];
        foreach ($keys as $index => $selectionKey) {
            if ($selectionKey->isAcceptable()) {
                $stream = $selectionKey->getStream();
                $resourceId = $stream->getResourceId();

                if ($resourceId === $this->frontendServer->getSocket()->getResourceId()) {
                    $this->addClient();
                    continue;
                }

                $this->getLogger()->err("Unknown resource id selected");
            }

            /** @var StreamTunnel $buffer */
            $buffer = $selectionKey->getAttachment();
            $uid = $buffer->getId();

            if (in_array($uid, $uidsToIgnore)) {
                continue;
            }

            try {
                $buffer->tunnel();
            } catch (Throwable $exception) {
                if ($exception instanceof IOException) {
                    /** @var SocketStream $input */
                    $this->disconnectClient($uid);
                    $uidsToIgnore[] = $uid;
                }

                if (!$exception instanceof EOFException) {
                    throw $exception;// disconnect may have altered other streams in selector, reset it
                }
            }
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
}