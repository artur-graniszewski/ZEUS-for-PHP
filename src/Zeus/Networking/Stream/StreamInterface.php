<?php

namespace Zeus\Networking\Stream;

/**
 * Interface ConnectionInterface
 * @package Zeus\Networking
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