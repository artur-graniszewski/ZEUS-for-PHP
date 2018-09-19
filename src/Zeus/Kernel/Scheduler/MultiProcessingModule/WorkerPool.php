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
use function count;

class WorkerPool
{
    const IPC_POOL_SIZE = 16384;

    /** @var SocketServer[] */
    private $ipcServers;

    /** @var SocketStream[] */
    private $ipcConnections = [];

    /** @var Selector */
    private $ipcSelector;
    
    /** @var Selector */
    private $backendSelector;

    /** @var bool */
    private $isTerminating = false;

    public function __construct(Selector $ipcSelector)
    {
        $this->ipcServers = new FixedCollection(static::IPC_POOL_SIZE);
        $this->ipcConnections = new FixedCollection(static::IPC_POOL_SIZE);
        $this->ipcSelector = $ipcSelector;
        $this->backendSelector = new Selector();
    }

    public function checkWorkers()
    {
        $ipcSelector = $this->ipcSelector;

        // read all keep-alive messages
        if (!$ipcSelector->select(0)) {
            return;
        }

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

    public function registerWorker(int $uid, SocketServer $pipe)
    {
        $this->ipcServers[$uid] = $pipe;
        $key = $pipe->getSocket()->register($this->backendSelector, SelectionKey::OP_ACCEPT);
        $key->attach(['uid' => $uid, 'server' => $pipe]);
    }

    public function registerWorkers()
    {
        //foreach ($this->ipcServers as $uid => $server) {
        $this->backendSelector->select(10);
        foreach ($this->backendSelector->getSelectionKeys() as $key) {
            try {
                $attachment = $key->getAttachment();
                $uid = $attachment['uid'];
                $server = $attachment['server'];
                $connection = $server->accept();
                $this->setStreamOptions($connection);
                $this->ipcConnections[$uid] = $connection;
                $this->ipcSelector->register($connection, SelectionKey::OP_READ);
                $this->ipcServers[$uid]->close();
                unset($this->ipcServers[$uid]);
                $this->backendSelector->unregister($server->getSocket());
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

    public function shutdown()
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

    public function disconnectWorkers() : bool
    {
        $this->checkWorkers();
        if (!$this->isTerminating()) {
            $this->registerWorkers();
        }

        return count($this->ipcConnections) === 0;
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