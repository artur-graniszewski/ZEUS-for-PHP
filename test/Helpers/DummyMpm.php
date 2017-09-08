<?php

namespace ZeusTest\Helpers;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\AbstractModule;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\MultiProcessingModuleCapabilities;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\MultiProcessingModuleInterface;

class DummyMpm extends AbstractModule implements MultiProcessingModuleInterface
{

    /**
     * @param EventManagerInterface $events
     * @return $this
     * @return mixed
     */
    public function attach(EventManagerInterface $events)
    {
        // TODO: Implement attach() method.

        return $this;
    }

    /**
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities()
    {
        return new MultiProcessingModuleCapabilities();
    }
}