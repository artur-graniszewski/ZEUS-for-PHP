<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Throwable;
use Zeus\IO\Exception\EOFException;
use Zeus\IO\Exception\IOException;
use Zeus\Kernel\IpcServer;
use Zeus\IO\Exception\SocketTimeoutException;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\SocketStream;
use Zeus\Kernel\System\Runtime;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;
use Zeus\ServerService\Shared\Networking\Message\FrontendElectionMessage;

use function stream_socket_client;
use function max;
use function in_array;
use function defined;

class FrontendService extends AbstractService
{
    /** @var string */
    private $workerHost = '127.0.0.3';

    /** @var SocketStream[] */
    private $backendStreams = [];

    /** @var SocketStream[] */
    private $frontendStreams = [];

    /** @var RegistratorService */
    private $registrator;

    /** @var AbstractNetworkServiceConfig */
    private $config;

    public function __construct(RegistratorService $registrator, AbstractNetworkServiceConfig $config)
    {
        $this->config = $config;
        $this->registrator = $registrator;
    }

    public function electFrontendWorkers(IpcServer $ipc)
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
        $ipc->send(new FrontendElectionMessage($frontendsAmount), IpcServer::AUDIENCE_AMOUNT, $frontendsAmount);
    }

    public function startFrontendServer(int $backlog)
    {
        $config = $this->config;
        $server = $this->getServer();
        $server->bind($config->getListenAddress(), $backlog, $config->getListenPort());
        $this->setSelector($this->newSelector());
        $server->register($this->getSelector(), SelectionKey::OP_ACCEPT);
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
            $this->getSelector()->unregister($frontendStream);
            unset($this->frontendStreams[$key]);
        }

        if (isset($this->backendStreams[$key])) {
            $backendStream = $this->backendStreams[$key];
            $this->getSelector()->unregister($backendStream);
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

        $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $this->getStreamContext());
        if (!$socket) {
            $registrator->notifyRegistrator(RegistratorService::STATUS_WORKER_FAILED, $uid, $port);
            $this->getLogger()->err("Connection refused: backend worker $uid failed");
            return false;
        }

        $backend = new SocketStream($socket);

        $client = $this->getServer()->accept();
        $this->setStreamOptions($client);

        $this->setStreamOptions($backend);
        $backend->setBlocking(true);
        $this->backendStreams[$uid] = $backend;
        $this->frontendStreams[$uid] = $client;

        $backendKey = $backend->register($this->getSelector(), SelectionKey::OP_READ);
        $frontendKey = $client->register($this->getSelector(), SelectionKey::OP_READ);
        $fromFrontendBuffer = new StreamTunnel($frontendKey, $backendKey);
        $fromBackendBuffer = new StreamTunnel($backendKey, $frontendKey);
        $fromBackendBuffer->setId($uid);
        $fromFrontendBuffer->setId($uid);
        $backendKey->attach($fromBackendBuffer);
        $frontendKey->attach($fromFrontendBuffer);

        return true;
    }

    public function selectStreams()
    {
        if (!$this->getSelector()->select(1234)) {
            return;
        }

        $keys = $this->getSelector()->getSelectionKeys();

        $uidsToIgnore = [];
        $serverResourceId = $this->getServer()->getSocket()->getResourceId();

        foreach ($keys as $index => $selectionKey) {
            if ($selectionKey->isAcceptable()) {
                $stream = $selectionKey->getStream();
                $resourceId = $stream->getResourceId();

                if ($resourceId === $serverResourceId) {
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

                if (!$exception instanceof IOException) {
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