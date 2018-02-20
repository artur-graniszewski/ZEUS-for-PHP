<?php

namespace Zeus\IO\Stream;

/**
 * Interface FlushableStreamInterface
 * @package Zeus\IO
 * @internal
 */
interface FlushableStreamInterface
{
    public function flush() : bool;
}