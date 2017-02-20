<?php

namespace Zeus\ServerService\Shared\React;

interface MessageComponentInterface
{
    /**
     * @param ConnectionInterface $connection
     * @throws \Exception
     */
    function onOpen(ConnectionInterface $connection);

    /**
     * @param ConnectionInterface $connection
     * @throws \Exception
     */
    function onClose(ConnectionInterface $connection);

    /**
     * @param ConnectionInterface $connection
     * @param \Exception $exception
     * @throws \Exception
     */
    function onError(ConnectionInterface $connection, $exception);

    /**
     * @param ConnectionInterface $connection
     * @param string $message
     * @throws \Exception
     */
    function onMessage(ConnectionInterface $connection, $message);
}