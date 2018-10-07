<?php

namespace ZeusTest\Helpers;

use Zeus\Kernel\Scheduler\Event\SchedulerStopped;
use Zeus\ServerService\ServerServiceInterface;
use Zeus\ServerService\Shared\AbstractServerService;

class DummyServerService extends AbstractServerService implements ServerServiceInterface
{
    public function start()
    {
        $config = $this->getConfig();
        if (isset($config['hang']) && $config['hang']) {
            return;
        }

        $event = new SchedulerStopped();
        $this->getLogger()->info("SERVICE STOPPED");
        $event->setScheduler($this->getScheduler());
        $event->setTarget($this->getScheduler());
        $this->getScheduler()->getEventManager()->triggerEvent($event);
    }

    public function stop()
    {

    }
}