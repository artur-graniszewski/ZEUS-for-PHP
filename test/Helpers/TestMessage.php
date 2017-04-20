<?php

namespace ZeusTest\Helpers;

use Zeus\ServerService\Shared\React\ConnectionInterface;
use Zeus\ServerService\Shared\React\MessageComponentInterface;

class TestMessage implements MessageComponentInterface
{
    protected $callback;

    public function __construct($callback)
    {
        $this->callback = $callback;
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
        call_user_func($this->callback, $message);
    }
}