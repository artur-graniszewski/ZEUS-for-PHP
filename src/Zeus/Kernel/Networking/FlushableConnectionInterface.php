<?php

namespace Zeus\Kernel\Networking;

/**
 * Interface FlushableConnectionInterface
 * @package Zeus\Kernel\Networking
 * @internal
 */
interface FlushableConnectionInterface
{
    public function flush();

    public function setReadBufferSize($size);

    public function setWriteBufferSize($size);
}