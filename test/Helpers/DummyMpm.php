<?php

namespace ZeusTest\Helpers;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\AbstractModule;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ModuleWrapper;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleCapabilities;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\IO\SocketServer;
use Zeus\Kernel\SchedulerInterface;

class DummyMpm extends AbstractModule
{
    /** @var SocketServer */
    protected $pipe;

    /** @var MultiProcessingModuleCapabilities */
    protected static $capabilities;

    public function attach(EventManagerInterface $eventManager)
    {
        $this->pipe = $this->getWrapper()->createPipe();

        $eventManager->attach(WorkerEvent::EVENT_CREATE, function (WorkerEvent $event) {
            $pid = $event->getParam('uid', getmypid());
            $status = $event->getWorker();
            $status->setProcessId($pid);
            $status->setThreadId(1);
            $status->setUid($pid);

            if ($event->getParam(SchedulerInterface::WORKER_SERVER)) {
                $event = clone $event;
                $event->setName(WorkerEvent::EVENT_INIT);
                $this->getWrapper()->getEventManager()->triggerEvent($event);
            }
        }, WorkerEvent::PRIORITY_INITIALIZE + 100);
    }

    protected function checkPipe()
    {
    }

    protected function connectToPipe(WorkerEvent $event)
    {
    }

    public static function getCapabilities() : MultiProcessingModuleCapabilities
    {
        if (!static::$capabilities) {
            static::$capabilities = new MultiProcessingModuleCapabilities();
        }

        return static::$capabilities;
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

    public function onKernelStop(SchedulerEvent $event)
    {
        // TODO: Implement onKernelStop() method.
    }
}