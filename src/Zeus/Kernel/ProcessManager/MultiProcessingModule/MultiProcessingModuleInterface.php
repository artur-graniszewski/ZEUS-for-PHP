<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;

interface MultiProcessingModuleInterface
{
    /**
     * @param EventManagerInterface $events
     * @return mixed
     */
    public function attach(EventManagerInterface $events);

    public function setLogger(LoggerInterface $logger);

    /**
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities();
}