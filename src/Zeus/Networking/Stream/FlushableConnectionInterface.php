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

    public function setReadBufferSize($size);

    public function setWriteBufferSize($size);
}