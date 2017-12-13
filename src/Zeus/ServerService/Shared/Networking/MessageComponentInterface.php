<?php

namespace Zeus\ServerService\Shared\Networking;
use Zeus\Networking\Stream\NetworkStreamInterface;

/**
 * Interface MessageComponentInterface
 * @package Zeus\ServerService\Shared\Networking
 * @internal
 */
interface MessageComponentInterface
{
    /**
     * @param NetworkStreamInterface $connection
     * @throws \Exception
     */
    function onOpen(NetworkStreamInterface $connection);

    /**
     * @param NetworkStreamInterface $connection
     * @throws \Exception
     */
    function onClose(NetworkStreamInterface $connection);

    /**
     * @param NetworkStreamInterface $connection
     * @param \Exception $exception
     * @throws \Exception
     */
    function onError(NetworkStreamInterface $connection, \Throwable $exception);

    /**
     * @param NetworkStreamInterface $connection
     * @param string $message
     * @throws \Exception
     */
    function onMessage(NetworkStreamInterface $connection, string $message);
}