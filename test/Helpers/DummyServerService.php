<?php

namespace ZeusTest\Helpers;

use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\ServerServiceInterface;
use Zeus\ServerService\Shared\AbstractServerService;

class DummyServerService extends AbstractServerService implements ServerServiceInterface
{
    public function start()
    {
        if (isset($this->config['hang']) && $this->config['hang']) {
            return;
        }

        $event = new SchedulerEvent();
        $this->logger->info("SERVICE STARTED");
        $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
        $this->getScheduler()->getEventManager()->triggerEvent($event);
    }

    public function stop()
    {

    }
}