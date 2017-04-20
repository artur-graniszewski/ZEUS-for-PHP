<?php

namespace Zeus\ServerService\Shared\Networking;

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

    public function read();

    /**
     * @param int $timeout
     * @return bool
     */
    public function select($timeout);

    public function write($data);

    public function end($data = null);
}