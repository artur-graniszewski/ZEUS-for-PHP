<?php

namespace ZeusTest\Helpers;

use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\ServerServiceInterface;
use Zeus\ServerService\Shared\AbstractServerService;

class DummyServerService extends AbstractServerService implements ServerServiceInterface
{
    public function start()
    {
        $event = new SchedulerEvent();
        $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
        $this->getScheduler()->getEventManager()->triggerEvent($event);
    }

    public function stop()
    {

    }
}