<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;

class SchedulerStopListener extends AbstractWorkerPoolListener
{
    public function __invoke(SchedulerEvent $event)
    {
        $this->driver->onSchedulerStop($event);
        $this->workerPool->setTerminating(true);

        while ($this->ipcConnections) {
            $this->workerPool->checkWorkers();
            if (!$this->workerPool->isTerminating()) {
                $this->workerPool->registerWorkers();
            }
            $this->driver->onWorkersCheck($event);
            if ($this->ipcConnections) {
                sleep(1);
                $amount = count($this->ipcConnections);
                //$this->getLogger()->info("Waiting for $amount workers to exit");
            }
        }
    }
}