<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\WorkerPool;

class AbstractWorkerPoolListener extends AbstractListener
{
    /** @var WorkerPool */
    protected $workerPool;

    public function __construct(MultiProcessingModuleInterface $driver, WorkerPool $workerPool)
    {
        parent::__construct($driver);
        $this->workerPool = $workerPool;
    }
}