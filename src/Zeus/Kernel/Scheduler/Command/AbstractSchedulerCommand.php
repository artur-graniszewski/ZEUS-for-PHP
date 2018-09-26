<?php

namespace Zeus\Kernel\Scheduler\Command;

use Zeus\Kernel\Scheduler\SchedulerEvent;

abstract class AbstractSchedulerCommand extends SchedulerEvent
{
    public function getName()
    {
        $name = parent::getName();
        
        return $name ? $name : static::class;
    }
}

