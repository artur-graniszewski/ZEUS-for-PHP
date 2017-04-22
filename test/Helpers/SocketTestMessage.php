<?php

namespace ZeusTest\Helpers;

use Zeus\ServerService\Shared\Networking\ConnectionInterface;
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
     * @param ConnectionInterface $connection
     * @throws \Exception
     */
    function onOpen(ConnectionInterface $connection)
    {
        // TODO: Implement onOpen() method.
    }

    /**
     * @param ConnectionInterface $connection
     * @throws \Exception
     */
    function onClose(ConnectionInterface $connection)
    {
        // TODO: Implement onClose() method.
    }

    /**
     * @param ConnectionInterface $connection
     * @param \Exception $exception
     * @throws \Exception
     */
    function onError(ConnectionInterface $connection, $exception)
    {
        // TODO: Implement onError() method.
    }

    /**
     * @param ConnectionInterface $connection
     * @param string $message
     * @throws \Exception
     */
    function onMessage(ConnectionInterface $connection, $message)
    {
        call_user_func($this->readCallback, $connection, $message);
    }

    public function onHeartBeat(ConnectionInterface $connection, $data = null)
    {
        $callback = $this->heartBeatCallback ? $this->heartBeatCallback : function() {};

        $callback($connection, $data);
    }
}