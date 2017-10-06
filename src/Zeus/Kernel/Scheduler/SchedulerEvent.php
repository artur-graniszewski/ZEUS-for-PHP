<?php

namespace Zeus\Kernel\Scheduler;

use Zend\EventManager\Event;

/**
 * @package Zeus\Kernel\Scheduler
 */
class SchedulerEvent extends Event
{
    const PRIORITY_FINALIZE = -100000;
    const PRIORITY_INITIALIZE = 50000;
    const PRIORITY_REGULAR = 0;

    const EVENT_WORKER_TERMINATED = 'workerTerminated';
    const EVENT_WORKER_TERMINATE = 'workerTerminate';

    const EVENT_SCHEDULER_START = 'schedulerStart';
    const EVENT_SCHEDULER_STOP = 'schedulerStop';
    const EVENT_SCHEDULER_LOOP = 'schedulerLoop';
    const EVENT_KERNEL_LOOP = 'kernelLoop';

    // WARNING: the following INTERNAL_* events should not be used in custom projects
    // and if used - are subjects to change and BC breaks.
    const INTERNAL_EVENT_KERNEL_START = 'kernelStart';
    const INTERNAL_EVENT_KERNEL_STOP = 'kernelStop';

    /**
     * @param WorkerInterface $target
     * @throws \LogicException
     */
    public function setTarget($target)
    {
        if (!($target instanceof WorkerInterface)) {
            throw new \LogicException("Target must be an instance of a WorkerInterface");
        }
        parent::setTarget($target);
    }

    /**
     * @return WorkerInterface
     */
    public function getTarget() : WorkerInterface
    {
        return parent::getTarget();
    }
}