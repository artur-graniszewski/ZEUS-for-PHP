<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;

interface MultiProcessingModuleInterface
{
    public static function isSupported(& $errorMessage = '') : bool;

    public static function getCapabilities() : MultiProcessingModuleCapabilities;

    public function getWrapper() : ModuleWrapper;

    public function setWrapper(ModuleWrapper $wrapper);

    public function onKernelStart(SchedulerEvent $event);

    public function onKernelLoop(SchedulerEvent $event);

    public function onKernelStop(SchedulerEvent $event);

    /* Scheduler Event handlers */
    public function onSchedulerInit(SchedulerEvent $schedulerEvent);

    public function onSchedulerLoop(SchedulerEvent $event);

    public function onWorkersCheck(SchedulerEvent $event);

    public function onSchedulerStop(SchedulerEvent $event);

    /* Worker Event handlers */
    public function onWorkerInit(WorkerEvent $event);

    public function onWorkerCreate(WorkerEvent $event);

    public function onWorkerLoop(WorkerEvent $event);

    public function onWorkerTerminate(WorkerEvent $event);

    public function onWorkerTerminated(WorkerEvent $event);

    public function onWorkerExit(WorkerEvent $event);
}