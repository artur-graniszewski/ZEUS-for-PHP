<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\ConfigInterface;
use Zeus\Kernel\Scheduler\WorkerLifeCycleFacade;

abstract class AbstractWorkerLifeCycleListener
{
    /** @var WorkerLifeCycleFacade */
    protected $workerLifeCycle;

    public function __construct(WorkerLifeCycleFacade $workerLifeCycle)
    {
        $this->workerLifeCycle = $workerLifeCycle;
    }

    protected function getUidFile(ConfigInterface $config) : string
    {
        // @todo: make it more sophisticated
        $fileName = sprintf("%s%s.pid", $config->getIpcDirectory(), $config->getServiceName());

        return $fileName;
    }

    protected function startWorkers(int $amount)
    {
        if ($amount === 0) {
            return;
        }

        for ($i = 0; $i < $amount; ++$i) {
            $this->workerLifeCycle->start([]);
        }
    }
}