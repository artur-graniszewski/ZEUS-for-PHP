<?php

namespace Zeus\Kernel\Scheduler;

use Zeus\Kernel\SchedulerInterface;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;

abstract class AbstractLifeCycleFacade
{
    use ExceptionLoggerTrait;

    /** @var SchedulerInterface */
    private $scheduler;

    public function setScheduler(SchedulerInterface $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function getScheduler() : SchedulerInterface
    {
        return $this->scheduler;
    }
}