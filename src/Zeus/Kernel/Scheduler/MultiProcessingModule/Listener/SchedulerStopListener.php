<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;

class SchedulerStopListener extends AbstractWorkerPoolListener
{
    public function __invoke(SchedulerEvent $event)
    {
        $this->workerPool->setTerminating(true);
        $this->driver->onSchedulerStop($event);

        while (!$this->workerPool->disconnectWorkers()) {
            sleep(1);
            //$amount = count($this->ipcConnections);
            //$this->getLogger()->info("Waiting for $amount workers to exit");
        }
    }
}