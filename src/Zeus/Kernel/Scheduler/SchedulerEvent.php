<?php

namespace Zeus\Kernel\Scheduler;

use Zeus\Kernel\SchedulerInterface;

/**
 * @package Zeus\Kernel\Scheduler
 * @internal
 */
class SchedulerEvent extends AbstractEvent
{
    const PRIORITY_FINALIZE = -100000;
    const PRIORITY_INITIALIZE = 50000;
    const PRIORITY_REGULAR = 0;

    const EVENT_START = 'schedulerStart';
    const EVENT_STOP = 'schedulerStop';
    const EVENT_LOOP = 'schedulerLoop';
    const EVENT_TICK = 'schedulerTick';
    const EVENT_SELECT = 'schedulerSelect';
    const EVENT_SELECT_TIMEOUT = 'schedulerSelectTimeout';
    const EVENT_TERMINATE = 'schedulerTerminate';


    // WARNING: the following INTERNAL_* events should not be used in custom projects
    // and if used - are subjects to change and BC breaks.
    const INTERNAL_EVENT_KERNEL_START = 'kernelStart';
    const INTERNAL_EVENT_KERNEL_STOP = 'kernelStop';
    const INTERNAL_EVENT_KERNEL_LOOP = 'kernelLoop';

    /** @var SchedulerInterface */
    private $scheduler;

    public function getScheduler() : SchedulerInterface
    {
        return $this->scheduler;
    }

    public function setScheduler(SchedulerInterface $scheduler)
    {
        $this->scheduler = $scheduler;
    }
}