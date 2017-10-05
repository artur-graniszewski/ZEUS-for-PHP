<?php

namespace Zeus\Networking\Stream;

/**
 * Interface SelectableStreamInterface
 * @package Zeus\Networking
 * @internal
 */
interface SelectableStreamInterface extends StreamInterface
{
    /**
     * @param int $timeout
     * @return bool
     */
    public function select(int $timeout) : bool;

    public function getLocalAddress() : string;
}