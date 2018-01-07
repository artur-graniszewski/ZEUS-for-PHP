<?php

namespace ZeusTest\Helpers;

use Zeus\Networking\Stream\NetworkStreamInterface;
use Zeus\Networking\Stream\FlushableStreamInterface;
use Zeus\Networking\Stream\SelectableStreamInterface;

class SocketTestNetworkStream implements NetworkStreamInterface, FlushableStreamInterface
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

    /**
     * @return bool
     */
    public function isConnectionClosed()
    {
        return $this->isConnectionClosed;
    }

    public function isReadable()
    {
        // TODO: Implement isReadable() method.
    }

    public function close()
    {
        $this->setIsConnectionClosed(true);
    }

    public function isWritable()
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

    public function read(string $ending = '') : string
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
}