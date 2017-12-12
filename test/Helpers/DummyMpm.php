<?php

namespace ZeusTest\Helpers;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\AbstractModule;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ModuleWrapper;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleCapabilities;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Networking\SocketServer;

class DummyMpm extends AbstractModule
{
    /** @var SocketServer */
    protected $pipe;

    public function attach(EventManagerInterface $eventManager)
    {
        $this->pipe = $this->getWrapper()->createPipe();

        $eventManager->attach(WorkerEvent::EVENT_WORKER_CREATE, function (WorkerEvent $event) {
            $pid = $event->getParam('uid', getmypid());
            $event->getWorker()->setProcessId($pid);
            $event->getWorker()->setThreadId(1);
            $event->getWorker()->setUid($pid);
        }, WorkerEvent::PRIORITY_INITIALIZE + 2);
    }

    protected function checkPipe()
    {
    }

    protected function connectToPipe(WorkerEvent $event)
    {
    }

    public static function getCapabilities() : MultiProcessingModuleCapabilities
    {
        return new MultiProcessingModuleCapabilities();
    }

    public function onWorkerCreate(WorkerEvent $event)
    {
        $pipe = $this->getWrapper()->createPipe();
        $event->setParam(ModuleWrapper::ZEUS_IPC_ADDRESS_PARAM, $this->pipe->getLocalAddress());
        $this->getWrapper()->setIpcAddress($pipe->getLocalAddress());
    }

    public function onWorkerInit(WorkerEvent $event)
    {
        $event->setParam(ModuleWrapper::ZEUS_IPC_ADDRESS_PARAM, $this->pipe->getLocalAddress());
        $this->getWrapper()->setIpcAddress($this->pipe->getLocalAddress());
    }

    public static function isSupported(& $errorMessage = ''): bool
    {
        return true;
    }

    public function onWorkerTerminate(WorkerEvent $event)
    {
        $this->getWrapper()->raiseWorkerExitedEvent($event->getParam('uid'), $event->getParam('uid'), 1);
        $event->stopPropagation(true);
    }

    public function isTerminating(): bool
    {
        return false;
    }

    public function onKernelStart(SchedulerEvent $event)
    {
        // TODO: Implement onKernelStart() method.
    }

    public function onKernelLoop(SchedulerEvent $event)
    {
        // TODO: Implement onKernelLoop() method.
    }

    public function onSchedulerStop(SchedulerEvent $event)
    {
        // TODO: Implement onSchedulerStop() method.
    }

    public function onWorkerExit(WorkerEvent $event)
    {
        // TODO: Implement onWorkerExit() method.
    }

    public function onSchedulerInit(SchedulerEvent $event)
    {
        // TODO: Implement onSchedulerInit() method.
    }

    public function onWorkerTerminated(WorkerEvent $event)
    {
        // TODO: Implement onWorkerTerminated() method.
    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {
        // TODO: Implement onSchedulerLoop() method.
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
        // TODO: Implement onWorkerLoop() method.
    }

    public function onWorkersCheck(SchedulerEvent $event)
    {
        // TODO: Implement onWorkersCheck() method.
    }
}