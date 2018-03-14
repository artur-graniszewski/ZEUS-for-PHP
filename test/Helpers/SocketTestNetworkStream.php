<?php

namespace ZeusTest\Helpers;

use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;

class SocketTestNetworkStream implements NetworkStreamInterface
{
    protected $dataSent = '';

    protected $dataReceived = '';

    protected $isConnectionClosed = false;

    protected $isConnectionWritable = true;

    protected $remoteAddress = '127.0.0.2:7071';

    protected $serverAddress = '127.0.0.1:7070';

    private $lastSelectionKey;

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
        if ($size === 0 || $size >= strlen($this->dataReceived)) {
            $result = $this->dataReceived;
            $this->dataReceived = '';

            return $result;
        }

        $result = substr($this->dataReceived, 0, $size);
        $this->dataReceived = substr($this->dataReceived, $size);

        return $result;
    }

    public function setDataReceived(string $data)
    {
        $this->dataReceived = $data;
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
        return crc32(spl_object_hash($this));
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
        $this->lastSelectionKey = new SelectionKey($this, $selector);

        return $this->lastSelectionKey;
    }

    public function getLastSelectionKey() : SelectionKey
    {
        return $this->lastSelectionKey;
    }

    public function setOption(int $option, $value)
    {
        // TODO: Implement setOption() method.
    }

    public function setBlocking(bool $isBlocking)
    {
        // TODO: Implement setBlocking() method.
    }
}