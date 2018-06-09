<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;

class KernelStartListener extends AbstractListener
{
    public function __invoke(SchedulerEvent $event)
    {
        $this->driver->onKernelStart($event);
        $this->driver->onWorkersCheck($event);
    }
}