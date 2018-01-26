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

    public function setReadBufferSize(int $size);

    public function setWriteBufferSize(int $size);
}