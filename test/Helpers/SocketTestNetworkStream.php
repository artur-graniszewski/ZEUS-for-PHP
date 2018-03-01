<?php

namespace ZeusTest\Helpers;

use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;

class SocketTestNetworkStream implements NetworkStreamInterface
{
    protected $dataSent = '';

    protected $isConnectionClosed = false;

    protected $isConnectionWritable = true;

    protected $remoteAddress = '127.0.0.2:7071';

    protected $serverAddress = '127.0.0.1:7070';

    public function write(string $data) : int
    {
        $this->dataSent .= $data;

        return strlen($data);
    }

    public function getRemoteAddress() : string
    {
        return $this->remoteAddress;
    }

    public function getLocalAddress() : string
    {
        return $this->serverAddress;
    }

    public function setDataSent(string $dataSent)
    {
        $this->dataSent = $dataSent;
    }

    public function setIsConnectionClosed(bool $isConnectionClosed)
    {
        $this->isConnectionClosed = $isConnectionClosed;
    }

    public function setRemoteAddress(string $remoteAddress)
    {
        $this->remoteAddress = $remoteAddress;
    }

    public function setServerAddress(string $serverAddress)
    {
        $this->serverAddress = $serverAddress;
    }

    public function getSentData() : string
    {
        $data = $this->dataSent;
        $this->dataSent = '';

        return $data;
    }

    public function isConnectionClosed() : bool
    {
        return $this->isConnectionClosed;
    }

    public function isReadable() : bool
    {
        // TODO: Implement isReadable() method.
        return true;
    }

    public function close()
    {
        $this->setIsConnectionClosed(true);
    }

    public function isWritable() : bool
    {
        return $this->isConnectionWritable;
    }

    public function setIsConnectionWritable(bool $isConnectionWritable)
    {
        $this->isConnectionWritable = $isConnectionWritable;
    }

    public function __construct($stream)
    {

    }

    public function read(int $size = 0) : string
    {
        // TODO: Implement read() method.
    }

    public function select(int $timeout) : bool
    {
        // TODO: Implement select() method.
        return true;
    }

    public function flush() : bool
    {
        return true;
    }

    public function setWriteBufferSize(int $size)
    {
        // TODO: Implement setWriteBufferSize() method.
    }

    public function setReadBufferSize(int $size)
    {
        // TODO: Implement setReadBufferSize() method.
    }

    public function getResource()
    {
        // TODO: Implement getResource() method.
    }

    public function getResourceId(): int
    {
        // TODO: Implement getResourceId() method.
    }

    public function isClosed(): bool
    {
        return $this->isConnectionClosed;
    }

    public function shutdown(int $shutdownType)
    {
        // TODO: Implement shutdown() method.
    }

    public function register(Selector $selector, int $operation): SelectionKey
    {
        // TODO: Implement register() method.
    }
}