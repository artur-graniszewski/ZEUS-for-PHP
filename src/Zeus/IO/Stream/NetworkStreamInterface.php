<?php

namespace Zeus\IO\Stream;

/**
 * Interface NetworkStreamInterface
 * @package Zeus\IO
 * @internal
 */
interface NetworkStreamInterface extends StreamInterface
{
    public function getLocalAddress() : string;

    /**
     * Returns the remote address (client IP) where this connection has been established from
     *
     * @return string|null remote address (client IP) or null if unknown
     */
    public function getRemoteAddress() : string;
}