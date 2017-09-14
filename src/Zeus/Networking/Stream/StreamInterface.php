<?php

namespace Zeus\Networking\Stream;

/**
 * Interface ConnectionInterface
 * @package Zeus\Networking
 * @internal
 */
interface StreamInterface extends ResourceInterface
{
    public function __construct($resource);

    public function close();

    public function isClosed() : bool;

    public function isWritable() : bool;

    public function read(string $ending = '') : string;

    public function isReadable() : bool;

    public function write(string $data);
}