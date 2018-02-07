<?php

namespace Zeus\IO\Stream;

abstract class AbstractSelectorAggregate extends AbstractSelector
{
    /**
     * @param AbstractStreamSelector $selector
     * @param $onSelectCallback
     * @param $onTimeoutCallback
     * @param int $timeout Timeout in milliseconds
     */
    public abstract function observe(AbstractStreamSelector $selector, $onSelectCallback, $onTimeoutCallback, int $timeout);

    public abstract function unregister(AbstractStreamSelector $selector);
}