<?php

namespace Zeus\IO\Stream;

abstract class AbstractSelector
{
    public abstract function register(SelectableStreamInterface $stream, int $operation = SelectionKey::OP_ALL) : SelectionKey;

    public abstract function unregister(SelectableStreamInterface $stream, int $operation = SelectionKey::OP_ALL);

    /**
     * @return SelectionKey[]
     */
    public abstract function getKeys() : array;

    /**
     * @param int $timeout Timeout in milliseconds
     * @return int
     */
    public abstract function select(int $timeout = 0) : int;

    /**
     * @return SelectionKey[]
     */
    public abstract function getSelectionKeys() : array;

    /**
     * @param SelectionKey[] $keys
     */
    protected abstract function setSelectionKeys(array $keys);
}