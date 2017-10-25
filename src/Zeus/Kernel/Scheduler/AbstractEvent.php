<?php

namespace Zeus\Kernel\Scheduler;

use Zend\EventManager\Event;
use Zeus\Kernel\Scheduler;

abstract class AbstractEvent extends Event
{
    /** @var Scheduler */
    private $scheduler;

    /**
     * @return Scheduler
     */
    public function getScheduler() : Scheduler
    {
        return $this->scheduler;
    }

    public function setScheduler(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }
}