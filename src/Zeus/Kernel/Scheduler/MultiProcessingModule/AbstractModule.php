<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;

abstract class AbstractModule implements MultiProcessingModuleInterface
{
    private $decorator;

    public function setDecorator(ModuleDecorator $decorator)
    {
        $this->decorator = $decorator;
    }

    public function getDecorator() : ModuleDecorator
    {
        return $this->decorator;
    }

    public function onKernelStart(SchedulerEvent $event)
    {

    }

    public function onKernelLoop(SchedulerEvent $event)
    {

    }

    public function onKernelStop(SchedulerEvent $event)
    {

    }

    public function onWorkerCreate(WorkerEvent $event)
    {

    }

    public function onSchedulerStop(SchedulerEvent $event)
    {

    }

    public function onWorkerTerminate(WorkerEvent $event)
    {

    }

    public function onWorkerExit(WorkerEvent $event)
    {

    }

    public function onSchedulerInit(SchedulerEvent $event)
    {

    }

    public function onWorkerInit(WorkerEvent $event)
    {

    }

    public function onWorkerTerminated(WorkerEvent $event)
    {

    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {

    }

    public function onWorkerLoop(WorkerEvent $event)
    {

    }

    public function onWorkersCheck(SchedulerEvent $event)
    {

    }

    public abstract static function getCapabilities() : MultiProcessingModuleCapabilities;
}