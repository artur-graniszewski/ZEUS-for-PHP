<?php

namespace Zeus\Networking\Stream;

/**
 * Interface FlushableStreamInterface
 * @package Zeus\Networking
 * @internal
 */
interface FlushableStreamInterface
{
    public function flush() : bool;

    public function setReadBufferSize(int $size);

    public function setWriteBufferSize(int $size);
}