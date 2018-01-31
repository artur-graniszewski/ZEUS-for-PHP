<?php

namespace Zeus\IO\Stream;

/**
 * Interface SelectableStreamInterface
 * @package Zeus\IO
 * @internal
 */
interface SelectableStreamInterface extends StreamInterface
{
    public function register(Selector $selector, int $operation) : SelectionKey;
}