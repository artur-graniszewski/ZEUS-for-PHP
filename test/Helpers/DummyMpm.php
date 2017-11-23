<?php

namespace ZeusTest\Helpers;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\AbstractModule;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleCapabilities;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleInterface;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Networking\SocketServer;

class DummyMpm extends AbstractModule
{
    /** @var SocketServer */
    protected $pipe;

    public function attach(EventManagerInterface $eventManager)
    {
        parent::attach($eventManager);

        $this->pipe = $this->createPipe();

        $eventManager->attach(WorkerEvent::EVENT_WORKER_CREATE, function (WorkerEvent $event) {

        }, WorkerEvent::PRIORITY_FINALIZE + 10);

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

    /**
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities() : MultiProcessingModuleCapabilities
    {
        return new MultiProcessingModuleCapabilities();
    }

    public function onWorkerCreate(WorkerEvent $event)
    {
        $pipe = $this->createPipe();
        $event->setParam(MultiProcessingModuleInterface::ZEUS_IPC_ADDRESS_PARAM, $this->pipe->getLocalAddress());
        $this->setIpcAddress($pipe->getLocalPort());
    }

    public function onWorkerInit(WorkerEvent $event)
    {
        $event->setParam(MultiProcessingModuleInterface::ZEUS_IPC_ADDRESS_PARAM, $this->pipe->getLocalAddress());
        $this->setIpcAddress($this->pipe->getLocalPort());
    }

    public static function isSupported(& $errorMessage = ''): bool
    {
        return true;
    }
}