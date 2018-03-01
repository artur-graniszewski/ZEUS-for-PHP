<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use RuntimeException;
use Throwable;
use Zeus\IO\Exception\IOException;
use Zeus\Kernel\IpcServer;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\SocketStream;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;

use function stream_socket_client;
use function max;
use function in_array;
use function defined;

class FrontendService extends AbstractService
{
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

    public function startFrontendServer(int $backlog)
    {
        $config = $this->config;
        $server = $this->getServer();
        $server->bind($config->getListenAddress(), $backlog, $config->getListenPort());
        $this->setSelector($this->newSelector());
        $server->register($this->getSelector(), SelectionKey::OP_ACCEPT);
    }

    private function disconnectClient(int $key)
    {
        $frontendStream = null;
        $backendStream = null;
        $frontendException = null;
        $backendException = null;
        $selector = $this->getSelector();

        if (isset($this->frontendStreams[$key])) {
            $frontendStream = $this->frontendStreams[$key];
            $selector->unregister($frontendStream);
            unset($this->frontendStreams[$key]);
        }

        if (isset($this->backendStreams[$key])) {
            $backendStream = $this->backendStreams[$key];
            $selector->unregister($backendStream);
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

            } catch (IOException $frontendException) {
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
            } catch (IOException $backendException) {
            }

            if (!$backendStream->isClosed()) {
                $backendStream->close();
            }
        }

        if ($frontendException) {
            throw $frontendException;
        }

        if ($backendException) {
            throw $backendException;
        }
    }

    private function connectToBackend()
    {
        $registrator = $this->registrator;
        list($uid, $address) = $registrator->getBackendWorker();

        $socket = @stream_socket_client("tcp://$address", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $this->getStreamContext());
        if (!$socket) {
            $registrator->notifyRegistrator(RegistratorService::STATUS_WORKER_FAILED, $uid, $address);

            throw new RuntimeException("Couldn't connect to backend #$uid: $errstr", $errno);
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
    }

    public function selectStreams()
    {
        $selector = $this->getSelector();
        if (!$selector->select(1234)) {
            return;
        }

        $keys = $selector->getSelectionKeys();

        $uidsToIgnore = [];
        $serverResourceId = $this->getServer()->getSocket()->getResourceId();

        foreach ($keys as $index => $selectionKey) {
            if ($selectionKey->isAcceptable()) {
                $stream = $selectionKey->getStream();
                $resourceId = $stream->getResourceId();

                if ($resourceId !== $serverResourceId) {
                    throw new RuntimeException("Unknown stream selected");

                }

                $this->connectToBackend();
                continue;
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