<?php

namespace Zeus\IO\Stream;

/**
 * Interface SelectableStreamInterface
 * @package Zeus\IO
 * @internal
 */
interface SelectableStreamInterface extends StreamInterface
{
    public function select(int $timeout) : bool;

    public function register(Selector $selector, int $operation) : SelectionKey;
}