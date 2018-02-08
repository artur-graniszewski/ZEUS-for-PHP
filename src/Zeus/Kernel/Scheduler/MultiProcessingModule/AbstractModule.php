<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;

abstract class AbstractModule implements MultiProcessingModuleInterface
{
    private $wrapper;

    public function setWrapper(ModuleWrapper $wrapper)
    {
        $this->wrapper = $wrapper;
    }

    public function getWrapper() : ModuleWrapper
    {
        return $this->wrapper;
    }

    public abstract function onKernelStart(SchedulerEvent $event);

    public abstract function onKernelLoop(SchedulerEvent $event);

    public abstract function onKernelStop(SchedulerEvent $event);

    public abstract function onWorkerCreate(WorkerEvent $event);

    public abstract function onSchedulerStop(SchedulerEvent $event);

    public abstract function onWorkerTerminate(WorkerEvent $event);

    public abstract function onWorkerExit(WorkerEvent $event);

    public abstract function onSchedulerInit(SchedulerEvent $event);

    public abstract function onWorkerInit(WorkerEvent $event);

    public abstract function onWorkerTerminated(WorkerEvent $event);

    public abstract function onSchedulerLoop(SchedulerEvent $event);

    public abstract function onWorkerLoop(WorkerEvent $event);

    public abstract function onWorkersCheck(SchedulerEvent $event);

    public abstract static function getCapabilities() : MultiProcessingModuleCapabilities;
}