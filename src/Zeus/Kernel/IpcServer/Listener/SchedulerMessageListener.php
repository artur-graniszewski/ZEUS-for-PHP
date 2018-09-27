<?php

namespace Zeus\Kernel\IpcServer\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;

class SchedulerMessageListener extends AbstractMessageListener
{
    public function __invoke(SchedulerEvent $event)
    {
        return parent::__invoke($event);
    }

}