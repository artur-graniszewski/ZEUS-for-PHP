<?php

namespace Zeus\Networking\Stream;

/**
 * Interface FlushableConnectionInterface
 * @package Zeus\Networking
 * @internal
 */
interface FlushableConnectionInterface
{
    public function flush();

    public function setReadBufferSize(int $size);

    public function setWriteBufferSize(int $size);
}