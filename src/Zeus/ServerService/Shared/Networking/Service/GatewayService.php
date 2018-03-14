<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Throwable;
use Zeus\Exception\NoSuchElementException;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\SocketStream;

use function stream_socket_client;
use function in_array;

class GatewayService extends AbstractService implements ServiceInterface
{
    /** @var SocketStream[] */
    private $backendStreams = [];

    /** @var SocketStream[] */
    private $frontendStreams = [];

    /** @var RegistratorService */
    private $registrator;

    public function __construct(RegistratorService $registrator)
    {
        $this->registrator = $registrator;
    }

    public function startService(string $address, int $backlog, int $port = -1)
    {
        $server = $this->getServer();
        $server->bind($address, $backlog, $port);
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
        $workerIPC = $registrator->getBackendIPC();
        /** @var SocketStream $backend */
        $backend = $workerIPC->getStream();
        $uid = $workerIPC->getUid();

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

        foreach ($keys as $index => $selectionKey) {
            if ($selectionKey->isAcceptable()) {
                $serverResourceId = $this->getServer()->getSocket()->getResourceId();
                $stream = $selectionKey->getStream();
                $resourceId = $stream->getResourceId();

                if ($resourceId !== $serverResourceId) {
                    throw new IOException("Unknown stream selected");
                }

                try {
                    $this->connectToBackend();
                } catch (NoSuchElementException $exception) {

                }
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

    public function stopService()
    {
        if (!$this->getServer()->isClosed()) {
            $this->getServer()->close();
        }
    }
}