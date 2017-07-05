<?php

namespace ZeusTest\Helpers;

use Zeus\Kernel\Networking\Stream\NetworkStreamInterface;
use Zeus\Kernel\Networking\Stream\FlushableConnectionInterface;

class SocketTestNetworkStream implements NetworkStreamInterface, FlushableConnectionInterface
{
    protected $dataSent = null;

    protected $isConnectionClosed = false;

    protected $isConnectionWritable = true;

    protected $remoteAddress = '127.0.0.2:7071';

    protected $serverAddress = '127.0.0.1:7070';

    /**
     * Send data to the connection
     * @param string $data
     * @return NetworkStreamInterface
     */
    public function write($data)
    {
        $this->dataSent .= $data;

        return $this;
    }

    /**
     * Close the connection
     * @param mixed[] $data
     */
    public function end($data = [])
    {
        $this->isConnectionClosed = true;
    }

    public function getRemoteAddress()
    {
        return $this->remoteAddress;
    }

    public function getServerAddress()
    {
        return $this->serverAddress;
    }

    /**
     * @param null $dataSent
     * @return $this
     */
    public function setDataSent($dataSent)
    {
        $this->dataSent = $dataSent;

        return $this;
    }

    /**
     * @param boolean $isConnectionClosed
     * @return $this
     */
    public function setIsConnectionClosed($isConnectionClosed)
    {
        $this->isConnectionClosed = $isConnectionClosed;

        return $this;
    }

    /**
     * @param string $remoteAddress
     * @return $this
     */
    public function setRemoteAddress($remoteAddress)
    {
        $this->remoteAddress = $remoteAddress;

        return $this;
    }

    /**
     * @param string $serverAddress
     * @return $this
     */
    public function setServerAddress($serverAddress)
    {
        $this->serverAddress = $serverAddress;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getSentData()
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
        // TODO: Implement close() method.
    }

    public function isWritable()
    {
        return $this->isConnectionWritable;
    }

    /**
     * @param bool $isConnectionWritable
     * @return $this
     */
    public function setIsConnectionWritable($isConnectionWritable)
    {
        $this->isConnectionWritable = $isConnectionWritable;

        return $this;
    }

    public function __construct($stream)
    {

    }

    public function read($ending = false)
    {
        // TODO: Implement read() method.
    }

    /**
     * @param int $timeout
     * @return bool
     */
    public function select($timeout)
    {
        // TODO: Implement select() method.
        return true;
    }

    public function flush()
    {
        // TODO: Implement flush() method.
    }

    public function setWriteBufferSize($size)
    {
        // TODO: Implement setWriteBufferSize() method.
    }

    public function setReadBufferSize($size)
    {
        // TODO: Implement setReadBufferSize() method.
    }
}