<?php

namespace ZeusTest\Helpers;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\Scheduler\MultiProcessingModule\AbstractModule;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleCapabilities;
use Zeus\Kernel\Scheduler\WorkerEvent;

class DummyMpm extends AbstractModule
{
    public function attach(EventManagerInterface $eventManager)
    {
        parent::attach($eventManager);

        $eventManager->attach(WorkerEvent::EVENT_WORKER_CREATE, function (WorkerEvent $event) {
            $pid = $event->getParam('uid', getmypid());
            $event->getWorker()->setProcessId($pid);
            $event->getWorker()->setThreadId(1);
            $event->getWorker()->setUid($pid);
        }, WorkerEvent::PRIORITY_FINALIZE + 10);
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
    public function getCapabilities()
    {
        return new MultiProcessingModuleCapabilities();
    }

    protected function onWorkerCreate(WorkerEvent $event)
    {
        $pipe = $this->createPipe();
        $event->setParam('connectionPort', $pipe->getLocalPort());
        $this->setConnectionPort($pipe->getLocalPort());
    }
}