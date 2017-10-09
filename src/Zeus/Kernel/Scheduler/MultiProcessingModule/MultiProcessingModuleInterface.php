<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;

interface MultiProcessingModuleInterface
{
    /**
     * @param EventManagerInterface $eventManager
     * @return mixed
     */
    public function attach(EventManagerInterface $eventManager);

    public function setLogger(LoggerInterface $logger);

    /**
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities();

    public function setSchedulerEvent(SchedulerEvent $schedulerEvent);
}