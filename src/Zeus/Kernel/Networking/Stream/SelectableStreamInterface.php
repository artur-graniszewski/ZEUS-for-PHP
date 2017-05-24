<?php

namespace Zeus\Kernel\Networking\Stream;

/**
 * Interface SelectableStreamInterface
 * @package Zeus\Kernel\Networking
 * @internal
 */
interface SelectableStreamInterface
{
    /**
     * @param int $timeout
     * @return bool
     */
    public function select($timeout);
}