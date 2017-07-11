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

    public function isWritable() : bool;

    public function read(string $ending = '');

    public function isReadable() : bool;

    public function write(string $data);

    public function end(string $data = '');
}