<?php

namespace Zeus\Kernel\Scheduler\Event;

use Zeus\Kernel\Scheduler\SchedulerEvent;

abstract class AbstractSchedulerEvent extends SchedulerEvent
{
    public function getName()
    {
        $name = parent::getName();
        
        return $name ? $name : static::class;
    }
}

