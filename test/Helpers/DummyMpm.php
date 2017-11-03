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

        $this->events->attach(WorkerEvent::EVENT_WORKER_CREATE, function (WorkerEvent $e) {

        }, WorkerEvent::PRIORITY_INITIALIZE + 10);
    }

    /**
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities()
    {
        return new MultiProcessingModuleCapabilities();
    }

    public function onWorkerCreate(WorkerEvent $event)
    {
        // TODO: Implement onWorkerCreate() method.
    }
}