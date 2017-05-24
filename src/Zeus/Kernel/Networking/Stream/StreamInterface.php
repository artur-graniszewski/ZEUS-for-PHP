<?php

namespace Zeus\Kernel\Networking\Stream;

/**
 * Interface ConnectionInterface
 * @package Zeus\Kernel\Networking
 * @internal
 */
interface StreamInterface
{
    public function __construct($stream);

    public function close();

    public function isWritable();

    public function read($ending = false);

    public function isReadable();

    public function write($data);

    public function end($data = null);
}