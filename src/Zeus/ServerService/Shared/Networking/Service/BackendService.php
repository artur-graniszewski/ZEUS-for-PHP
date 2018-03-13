<?php

namespace Zeus\ServerService\Shared\Networking\Service;

use Throwable;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\IO\Stream\SelectionKey;
use Zeus\Kernel\Scheduler\Worker;
use Zeus\IO\Exception\IOException;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\ServerService\Shared\Networking\HeartBeatMessageInterface;
use Zeus\ServerService\Shared\Networking\MessageComponentInterface;

use function time;
use function is_null;

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
        return !is_null($this->clientStream) && !$this->getClientStream()->isClosed();
    }

    public function setClientStream(NetworkStreamInterface $stream)
    {
        $this->clientStream = $stream;
    }

    private function onHeartBeat()
    {
        if (!$this->isClientConnected()) {
            return;
        }

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

    public function checkMessages(Worker $worker)
    {
        $listener = $this->messageListener;
        $exception = null;

        try {
            if (!$this->isClientConnected()) {
                $worker->setWaiting();
                try {
                    if ($this->getSelector()->select(1000)) {
                        $worker->getStatus()->incrementNumberOfFinishedTasks(1);
                        $worker->setRunning();
                        $clientStream = $this->getServer()->accept();
                        try {
                            $this->setStreamOptions($clientStream);
                        } catch (UnsupportedOperationException $exception) {
                            // this may happen in case of disabled PHP extension, or definitely happen in case of HHVM
                        }
                    } else {
                        return;
                    }
                } catch (Throwable $exception) {
                    $worker->setWaiting();

                    return;
                }

                $this->setClientStream($clientStream);
                $listener->onOpen($clientStream);
            }

            $clientStream = $this->getClientStream();

            if (!$clientStream->isReadable()) {
                $clientStream->close();
                $worker->setWaiting();
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
                    $worker->setWaiting();

                    return;
                }
                $this->onHeartBeat();
            }

            // nothing wrong happened, data was handled, resume main event
            if (!$clientStream->isClosed()) {
                $this->onHeartBeat();

                return;
            }
        } catch (IOException $streamException) {
            $this->onHeartBeat();
        } catch (Throwable $exception) {
        }

        if ($this->isClientConnected()) {
            try {
                if ($exception) {
                    $listener->onError($this->getClientStream(), $exception);
                } else {
                    $listener->onClose($this->getClientStream());
                }
            } catch (Throwable $exception) {
            }

            $this->closeConnection();
        }

        $worker->setWaiting();

        if ($exception) {
            throw $exception;
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
}