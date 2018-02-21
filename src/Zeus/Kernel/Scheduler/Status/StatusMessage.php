<?php

namespace Zeus\Kernel\Scheduler\Status;

/**
 * @internal
 */
class StatusMessage
{
    private $params = [];

    public function __construct(array $params)
    {
        $this->params = $params;
    }

    public function getParams()
    {
        return $this->params;
    }
}