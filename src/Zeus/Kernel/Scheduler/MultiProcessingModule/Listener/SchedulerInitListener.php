<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;

class SchedulerInitListener extends AbstractListener
{
    public function __invoke(SchedulerEvent $event)
    {
        $this->driver->onSchedulerInit($event);
    }
}