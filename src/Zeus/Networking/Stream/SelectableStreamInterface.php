<?php

namespace Zeus\Networking\Stream;

/**
 * Interface SelectableStreamInterface
 * @package Zeus\Networking
 * @internal
 */
interface SelectableStreamInterface extends StreamInterface, NetworkStreamInterface
{
    public function select(int $timeout) : bool;

    public function register(Selector $selector, int $operation) : SelectionKey;
}