<?php

namespace ZeusTest\Helpers;

use Zeus\Kernel\Scheduler\MultiProcessingModule\AbstractModule;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleCapabilities;

class DummyMpm extends AbstractModule
{
    /**
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities()
    {
        return new MultiProcessingModuleCapabilities();
    }
}