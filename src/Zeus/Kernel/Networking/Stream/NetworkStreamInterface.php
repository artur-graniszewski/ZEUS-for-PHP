<?php

namespace Zeus\Kernel\Networking\Stream;

/**
 * Interface NetworkStreamInterface
 * @package Zeus\Kernel\Networking
 * @internal
 */
interface NetworkStreamInterface
{
    public function getServerAddress();

    /**
     * Returns the remote address (client IP) where this connection has been established from
     *
     * @return string|null remote address (client IP) or null if unknown
     */
    public function getRemoteAddress();
}