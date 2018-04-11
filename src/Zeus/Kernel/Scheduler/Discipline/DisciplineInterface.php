<?php

namespace Zeus\Kernel\Scheduler\Discipline;

use Zeus\Kernel\Scheduler\ConfigInterface;
use Zeus\Kernel\Scheduler\Shared\WorkerCollection;
use Zeus\Kernel\Scheduler\Status\WorkerState;

interface DisciplineInterface
{
    public function setConfig(ConfigInterface $config);

    public function setWorkersCollection(WorkerCollection $workers);

    public function getAmountOfWorkersToCreate() : int;

    /**
     * @return WorkerState[]
     */
    public function getWorkersToTerminate() : array;
}