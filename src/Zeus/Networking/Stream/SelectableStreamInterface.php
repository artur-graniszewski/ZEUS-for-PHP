<?php

namespace Zeus\Networking\Stream;

/**
 * Interface SelectableStreamInterface
 * @package Zeus\Networking
 * @internal
 */
interface SelectableStreamInterface
{
    /**
     * @param int $timeout
     * @return bool
     */
    public function select(int $timeout) : bool;
}