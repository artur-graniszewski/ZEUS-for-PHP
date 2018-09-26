<?php

namespace Zeus\Kernel\Scheduler\Command;

use Zeus\Kernel\Scheduler\WorkerEvent;

abstract class AbstractWorkerCommand extends WorkerEvent
{
    public function getName()
    {
        $name = parent::getName();
        
        return $name ? $name : static::class;
    }
}

