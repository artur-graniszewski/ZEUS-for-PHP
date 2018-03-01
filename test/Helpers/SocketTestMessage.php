<?php

namespace ZeusTest\Helpers;

use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\ServerService\Shared\Networking\MessageComponentInterface;
use Zeus\ServerService\Shared\Networking\HeartBeatMessageInterface;

class SocketTestMessage implements MessageComponentInterface, HeartBeatMessageInterface
{
    private $readCallback;

    private $errorCallback;

    private $heartBeatCallback;


    public function setErrorCallback($callback)
    {
        $this->errorCallback = $callback;
    }

    public function setHeartBeatCallback($callback)
    {
        $this->heartBeatCallback = $callback;
    }

    public function setMessageCallback($callback)
    {
        $this->readCallback = $callback;
    }

    /**
     * @param \Zeus\IO\Stream\NetworkStreamInterface $connection
     * @throws \Exception
     */
    function onOpen(NetworkStreamInterface $connection)
    {
        // TODO: Implement onOpen() method.
    }

    /**
     * @param \Zeus\IO\Stream\NetworkStreamInterface $connection
     * @throws \Exception
     */
    function onClose(NetworkStreamInterface $connection)
    {
        // TODO: Implement onClose() method.
    }

    /**
     * @param NetworkStreamInterface $connection
     * @param \Throwable $exception
     * @throws \Throwable
     */
    function onError(NetworkStreamInterface $connection, \Throwable $exception)
    {
        if ($this->errorCallback) {
            call_user_func($this->errorCallback, $connection, $exception);
        }
    }

    /**
     * @param NetworkStreamInterface $connection
     * @param string $message
     * @throws \Exception
     */
    function onMessage(NetworkStreamInterface $connection, string $message)
    {
        call_user_func($this->readCallback, $connection, $message);
    }

    public function onHeartBeat(NetworkStreamInterface $connection, $data = null)
    {
        $callback = $this->heartBeatCallback ? $this->heartBeatCallback : function() {};

        $callback($connection, $data);
    }
}