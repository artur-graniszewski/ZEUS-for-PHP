<?php

namespace ZeusTest\Helpers;


use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\MultiProcessingModuleCapabilities;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\MultiProcessingModuleInterface;

class DummyMpm implements MultiProcessingModuleInterface
{

    /**
     * @param EventManagerInterface $events
     * @return $this
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
        // TODO: Implement getCapabilities() method.

        return new MultiProcessingModuleCapabilities();
    }
}