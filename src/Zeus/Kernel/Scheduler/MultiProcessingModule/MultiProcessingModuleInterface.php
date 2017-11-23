<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;

interface MultiProcessingModuleInterface
{
    const LOOPBACK_INTERFACE = '127.0.0.1';

    const UPSTREAM_CONNECTION_TIMEOUT = 5;

    const ZEUS_IPC_ADDRESS_PARAM = 'zeusIpcAddress';

    /**
     * @param EventManagerInterface $eventManager
     * @return mixed
     */
    public function attach(EventManagerInterface $eventManager);

    public function setLogger(LoggerInterface $logger);

    public function getLogger() : LoggerInterface;

    public function getIpcAddress() : string;

    public function setIpcAddress(string $address);

    /**
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities() : MultiProcessingModuleCapabilities;

    public function setSchedulerEvent(SchedulerEvent $schedulerEvent);

    public function setWorkerEvent(WorkerEvent $workerEvent);

    public function getSchedulerEvent() : SchedulerEvent;

    public function getWorkerEvent() : WorkerEvent;

    public function onKernelStart(SchedulerEvent $event);

    public function onKernelLoop(SchedulerEvent $event);

    /* Scheduler Event handlers */
    public function onSchedulerInit(SchedulerEvent $schedulerEvent);

    public function onSchedulerLoop(SchedulerEvent $event);

    public function onSchedulerStop(SchedulerEvent $event);

    /* Worker Event handlers */
    public function onWorkerInit(WorkerEvent $event);

    public function onWorkerCreate(WorkerEvent $event);

    public function onWorkerLoop(WorkerEvent $event);

    public function onWorkerTerminate(SchedulerEvent $event);

    public function onWorkerTerminated(WorkerEvent $event);

    public function onWorkerExit(WorkerEvent $event);
}