<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\WorkerIPC;
use Zeus\Kernel\Scheduler\MultiProcessingModule\WorkerPool;

abstract class AbstractWorkerIPCListener extends AbstractWorkerPoolListener
{
    /** @var WorkerIPC */
    protected $workerIPC;

    public function __construct(MultiProcessingModuleInterface $driver, WorkerPool $workerPool, WorkerIPC $workerIPC)
    {
        parent::__construct($driver, $workerPool);
        $this->workerIPC = $workerIPC;
    }
}