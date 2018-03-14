<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Throwable;
use Zeus\IO\Exception\SocketTimeoutException;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\IO\Stream\SelectionKey;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\ServerService\Shared\Networking\HeartBeatMessageInterface;
use Zeus\ServerService\Shared\Networking\MessageComponentInterface;

use function time;

class BackendService extends AbstractService implements ServiceInterface
{
    /** @var int */
    private $lastTickTime = 0;

    /** @var MessageComponentInterface */
    private $messageListener;

    /** @var NetworkStreamInterface */
    private $clientStream;

    public function __construct(MessageComponentInterface $messageListener)
    {
        $this->messageListener = $messageListener;
    }

    public function getClientStream() : NetworkStreamInterface
    {
        return $this->clientStream;
    }

    public function isClientConnected() : bool
    {
        return null !== $this->clientStream && !$this->getClientStream()->isClosed();
    }

    public function setClientStream(NetworkStreamInterface $stream)
    {
        $this->clientStream = $stream;
    }

    private function onHeartBeat()
    {
        $now = time();
        if ($this->messageListener instanceof HeartBeatMessageInterface && $this->lastTickTime !== $now) {
            $this->lastTickTime = $now;
            $this->messageListener->onHeartBeat($this->getClientStream());
        }
    }

    private function closeConnection()
    {
        if ($this->isClientConnected()) {
            $this->getClientStream()->shutdown(STREAM_SHUT_RD);
            $this->getClientStream()->close();
        }
    }

    private function acceptClient()
    {
        if (!$this->getSelector()->select(1000)) {
            return false;
        }

        try {
            $clientStream = $this->getServer()->accept();
        } catch (SocketTimeoutException $exception) {
            return false;
        }

        $this->setClientStream($clientStream);
        try {
            $this->setStreamOptions($clientStream);
        } catch (UnsupportedOperationException $exception) {
            // this may happen in case of disabled PHP extension, or definitely happen in case of HHVM
        }

        try {
            $this->messageListener->onOpen($clientStream);

            return true;
        } catch (Throwable $exception) {
            $this->handleException($exception);

            return false;
        }
    }

    public function startService(string $workerHost, int $backlog, int $port = -1)
    {
        $server = $this->getServer();
        $server->bind($workerHost, $backlog, $port);
        $this->setSelector($this->newSelector());
        $server->getSocket()->register($this->getSelector(), SelectionKey::OP_ACCEPT);
    }

    public function stopService()
    {
        if (!$this->getServer()->isClosed()) {
            $this->getServer()->close();
        }

        if ($this->isClientConnected()) {
            $this->getClientStream()->close();
        }
    }

    private function checkClientStream()
    {
        $listener = $this->messageListener;
        $clientStream = $this->getClientStream();

        if (!$clientStream->isReadable()) {
            $listener->onClose($clientStream);
            $this->closeConnection();
            return;
        }

        $selector = $this->newSelector();
        $clientStream->register($selector, SelectionKey::OP_READ);
        while ($selector->select(1000) > 0) {
            $data = $clientStream->read();
            if ($data !== '') {
                $listener->onMessage($clientStream, $data);

                if ($clientStream->isClosed()) {
                    break;
                }
                do {
                    $flushed = $clientStream->flush();
                } while (!$flushed);
            } else {
                // its an EOF
                $listener->onClose($clientStream);
                $this->closeConnection();

                return;
            }
        }

        // nothing wrong happened, data was handled, resume main event
        if ($this->isClientConnected()) {
            $this->onHeartBeat();
        } else {
            $listener->onClose($clientStream);
        }
    }

    public function checkMessages()
    {
        if (!$this->isClientConnected() && !$this->acceptClient()) {
            return;
        }

        try {
            $this->checkClientStream();
        } catch (Throwable $exception) {
            $this->handleException($exception);
        }
    }

    private function handleException(Throwable $exception)
    {
        try {
            if ($this->isClientConnected()) {
                $this->messageListener->onError($this->getClientStream(), $exception);
                $exception = null;
            }
        } catch (Throwable $exception) {
        }

        $this->closeConnection();

        if ($exception) {
            throw $exception;
        }
    }
}