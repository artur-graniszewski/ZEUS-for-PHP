<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use RuntimeException;
use Throwable;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Exception\IOException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;

class WorkerIPC
{
    /** @var SocketStream */
    private $ipc;

    /** @var Selector */
    private $parentIpcSelector;

    private function setStreamOptions(NetworkStreamInterface $stream)
    {
        try {
            $stream->setOption(SO_KEEPALIVE, 1);
            $stream->setOption(TCP_NODELAY, 1);
        } catch (UnsupportedOperationException $e) {
            // this may happen in case of disabled PHP extension, or definitely happen in case of HHVM
        }
    }

    public function connectToPipe(string $ipcAddress)
    {
        $stream = @stream_socket_client($ipcAddress, $errno, $errstr, ModuleDecorator::UPSTREAM_CONNECTION_TIMEOUT);

        if (!$stream) {
            throw new IOException("Upstream pipe unavailable on: " . $ipcAddress);
        }

        $ipc = new SocketStream($stream);
        $ipc->setBlocking(false);
        $this->setStreamOptions($ipc);
        $this->parentIpcSelector = new Selector();
        $ipc->register($this->parentIpcSelector, SelectionKey::OP_READ);
        $this->ipc = $ipc;
    }

    public function checkPipe()
    {
        static $lastCheck;

        $now = time();
        if ($lastCheck === $now) {
            return;
        }

        $ipc = $this->ipc;

        $lastCheck = $now;
        if ($ipc->isClosed() || ($this->parentIpcSelector->select(0) === 1 && in_array($ipc->read(), ['@', ''])) || (!$ipc->write("!") && !$ipc->flush())) {
            throw new RuntimeException("Pipe is closed");
        }
    }

    /**
     * @todo Make it private!!!! Now its needed only by DummyMPM test module
     * @internal
     */
    public function createPipe() : SocketServer
    {
        $socketServer = new SocketServer(0, 500, ModuleDecorator::LOOPBACK_INTERFACE);
        $socketServer->setSoTimeout(10);

        return $socketServer;
    }

    public function closePipe()
    {
        try {
            $ipc = $this->ipc;
            if ($ipc && !$ipc->isClosed()) {
                $ipc->close();
            }
        } catch (Throwable $ex) {

        }
    }
}