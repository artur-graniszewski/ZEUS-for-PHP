<?php

namespace Zeus\Kernel\Networking;

/**
 * Interface ConnectionInterface
 * @package Zeus\Kernel\Networking
 * @internal
 */
interface ConnectionInterface
{
    public function __construct($stream);

    public function getServerAddress();

    /**
     * Returns the remote address (client IP) where this connection has been established from
     *
     * @return string|null remote address (client IP) or null if unknown
     */
    public function getRemoteAddress();

    public function close();

    public function isWritable();

    public function read($ending = false);

    public function isReadable();

    /**
     * @param int $timeout
     * @return bool
     */
    public function select($timeout);

    public function write($data);

    public function end($data = null);
}