<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;

class SchedulerStopListener extends AbstractWorkerLifeCycleListener
{
    public function __invoke(SchedulerEvent $event)
    {
        $this->workerLifeCycle->stop($event->getScheduler()->getWorker(), true);

        $count = 0;
        while (@file_get_contents($this->getUidFile($event->getScheduler()->getConfig())) && $count < 5) {
            sleep(1);
            $count++;
        }
    }
}