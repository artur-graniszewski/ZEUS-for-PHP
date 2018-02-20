<?php

namespace Zeus\IO\Stream;

/**
 * Interface ConnectionInterface
 * @package Zeus\IO
 * @internal
 */
interface StreamInterface extends ResourceInterface
{
    public function __construct($resource);

    public function close();

    public function isClosed() : bool;

    public function isWritable() : bool;

    public function read(int $size = 0) : string;

    public function isReadable() : bool;

    public function write(string $data): int;

    public function flush() : bool;

    public function setReadBufferSize(int $size);

    public function setWriteBufferSize(int $size);
}