<?php

namespace Zeus\Networking\Stream;

/**
 * Interface NetworkStreamInterface
 * @package Zeus\Networking
 * @internal
 */
interface NetworkStreamInterface
{
    public function getLocalAddress() : string;

    /**
     * Returns the remote address (client IP) where this connection has been established from
     *
     * @return string|null remote address (client IP) or null if unknown
     */
    public function getRemoteAddress() : string;
}