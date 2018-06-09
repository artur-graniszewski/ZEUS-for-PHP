<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule\Listener;

use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleInterface;

abstract class AbstractListener
{
    /** @var MultiProcessingModuleInterface */
    protected $driver;

    public function __construct(MultiProcessingModuleInterface $driver)
    {
        $this->driver = $driver;
    }
}