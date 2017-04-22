<?php

namespace ZeusTest\Helpers;

use React\Stream\WritableStreamInterface;
use Zeus\ServerService\Shared\React\ConnectionInterface;

class ReactTestConnection implements ConnectionInterface
{
    protected $dataSent = null;

    protected $isConnectionClosed = false;

    protected $isConnectionWritable = true;

    protected $remoteAddress = '127.0.0.2:7071';

    protected $serverAddress = '127.0.0.1:7070';

    /**
     * Send data to the connection
     * @param string $data
     * @return ConnectionInterface
     */
    public function write($data)
    {
        $this->dataSent .= $data;
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

    public function on($event, callable $listener)
    {
        // TODO: Implement on() method.
    }

    public function once($event, callable $listener)
    {
        // TODO: Implement once() method.
    }

    public function removeListener($event, callable $listener)
    {
        // TODO: Implement removeListener() method.
    }

    public function removeAllListeners($event = null)
    {
        // TODO: Implement removeAllListeners() method.
    }

    public function listeners($event)
    {
        // TODO: Implement listeners() method.
    }

    public function emit($event, array $arguments = [])
    {
        // TODO: Implement emit() method.
    }

    public function isReadable()
    {
        // TODO: Implement isReadable() method.
    }

    public function pause()
    {
        // TODO: Implement pause() method.
    }

    public function resume()
    {
        // TODO: Implement resume() method.
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        // TODO: Implement pipe() method.
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
     * @return ReactTestConnection
     */
    public function setIsConnectionWritable($isConnectionWritable)
    {
        $this->isConnectionWritable = $isConnectionWritable;

        return $this;
    }
}