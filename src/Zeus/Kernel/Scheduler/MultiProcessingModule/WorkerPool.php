<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Throwable;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Exception\IOException;
use Zeus\IO\Exception\SocketTimeoutException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;
use Zeus\Kernel\Scheduler\Shared\FixedCollection;

use function array_search;

class WorkerPool
{
    /** @var SocketServer[] */
    private $ipcServers;

    /** @var SocketStream[] */
    private $ipcConnections = [];

    /** @var Selector */
    private $ipcSelector;

    /** @var bool */
    private $isTerminating = false;

    public function __construct(Selector $ipcSelector)
    {
        $this->ipcServers = new FixedCollection(1024);
        $this->ipcSelector = $ipcSelector;
    }

    public function checkWorkers()
    {
        $ipcSelector = $this->ipcSelector;

        // read all keep-alive messages
        if ($ipcSelector->select(0)) {
            foreach ($ipcSelector->getSelectionKeys() as $key) {
                /** @var SocketStream $stream */
                $stream = $key->getStream();
                try {
                    $stream->read();
                } catch (Throwable $ex) {
                    $uid = array_search($stream, $this->ipcConnections);
                    if ($uid) {
                        $this->unregisterWorker($uid);
                    }
                }
            }
        }
    }

    public function registerWorker(int $uid, SocketServer $pipe)
    {
        $this->ipcServers[$uid] = $pipe;
    }

    public function registerWorkers()
    {
        foreach ($this->ipcServers as $uid => $server) {
            try {
                $connection = $server->accept();
                $this->setStreamOptions($connection);
                $this->ipcConnections[$uid] = $connection;
                $this->ipcSelector->register($connection, SelectionKey::OP_READ);
                $this->ipcServers[$uid]->close();
                unset($this->ipcServers[$uid]);
            } catch (SocketTimeoutException $exception) {
                // @todo: verify if nothing to do?
            }
        }
    }

    public function unregisterWorker(int $uid)
    {
        if (!isset($this->ipcConnections[$uid])) {
            return;
        }

        $connection = $this->ipcConnections[$uid];
        unset($this->ipcConnections[$uid]);
        $this->ipcSelector->unregister($connection);
        try {
            $connection->write('@');
            $connection->flush();
            $connection->shutdown(STREAM_SHUT_RD);
        } catch (IOException $ex) {

        }

        $connection->close();
    }

    public function unregisterWorkers()
    {
        try {
            foreach ($this->ipcServers as $server) {
                $socket = $server->getSocket();
                $socket->shutdown(STREAM_SHUT_RD);
                $socket->close();
            }
        } catch (Throwable $ex) {

        }
    }

    private function setStreamOptions(NetworkStreamInterface $stream)
    {
        try {
            $stream->setOption(SO_KEEPALIVE, 1);
            $stream->setOption(TCP_NODELAY, 1);
        } catch (UnsupportedOperationException $e) {
            // this may happen in case of disabled PHP extension, or definitely happen in case of HHVM
        }
    }

    public function isTerminating(): bool
    {
        return $this->isTerminating;
    }

    public function setTerminating(bool $isTerminating)
    {
        $this->isTerminating = $isTerminating;
    }
}