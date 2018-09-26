<?php

namespace Zeus\Kernel\Scheduler\Event;

use Zeus\Kernel\Scheduler\WorkerEvent;

abstract class AbstractWorkerEvent extends WorkerEvent
{
    public function getName()
    {
        $name = parent::getName();
        
        return $name ? $name : static::class;
    }
}

