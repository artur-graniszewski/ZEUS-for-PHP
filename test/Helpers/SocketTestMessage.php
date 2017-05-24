<?php

namespace ZeusTest\Helpers;

use Zeus\Kernel\Networking\Stream\NetworkStreamInterface;
use Zeus\ServerService\Shared\Networking\MessageComponentInterface;
use Zeus\ServerService\Shared\Networking\HeartBeatMessageInterface;

class SocketTestMessage implements MessageComponentInterface, HeartBeatMessageInterface
{
    protected $readCallback;
    /**
     * @var
     */
    private $heartBeatCallback;

    public function __construct($readCallback, $heartBeatCallback = null)
    {
        $this->readCallback = $readCallback;
        $this->heartBeatCallback = $heartBeatCallback;
    }

    /**
     * @param \Zeus\Kernel\Networking\Stream\NetworkStreamInterface $connection
     * @throws \Exception
     */
    function onOpen(NetworkStreamInterface $connection)
    {
        // TODO: Implement onOpen() method.
    }

    /**
     * @param \Zeus\Kernel\Networking\Stream\NetworkStreamInterface $connection
     * @throws \Exception
     */
    function onClose(NetworkStreamInterface $connection)
    {
        // TODO: Implement onClose() method.
    }

    /**
     * @param NetworkStreamInterface $connection
     * @param \Exception $exception
     * @throws \Exception
     */
    function onError(NetworkStreamInterface $connection, $exception)
    {
        // TODO: Implement onError() method.
    }

    /**
     * @param NetworkStreamInterface $connection
     * @param string $message
     * @throws \Exception
     */
    function onMessage(NetworkStreamInterface $connection, $message)
    {
        call_user_func($this->readCallback, $connection, $message);
    }

    public function onHeartBeat(NetworkStreamInterface $connection, $data = null)
    {
        $callback = $this->heartBeatCallback ? $this->heartBeatCallback : function() {};

        $callback($connection, $data);
    }
}