<?php

namespace Zeus\IO\Stream;

abstract class AbstractSelectorAggregate extends AbstractSelector
{
    /**
     * @param AbstractStreamSelector $selector
     * @param $callback
     * @param int $timeout Timeout in milliseconds
     */
    public abstract function register(AbstractStreamSelector $selector, $callback, int $timeout);

    public abstract function unregister(AbstractStreamSelector $selector);
}