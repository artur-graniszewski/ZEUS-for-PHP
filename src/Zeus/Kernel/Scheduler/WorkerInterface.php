<?php

namespace Zeus\Kernel\Scheduler;

use Zend\EventManager\EventManagerInterface;

interface WorkerInterface
{
    /**
     * @return EventManagerInterface
     */
    public function getEventManager();
}