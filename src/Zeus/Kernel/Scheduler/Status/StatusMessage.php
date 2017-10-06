<?php

namespace Zeus\Kernel\Scheduler\Status;

class StatusMessage
{
    protected $params = [];

    public function __construct($params = [])
    {
        $this->params = $params;
    }

    public function getParams()
    {
        return $this->params;
    }
}