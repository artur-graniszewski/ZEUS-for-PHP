<?php

namespace Zeus\Kernel\Scheduler;

use Zeus\Kernel\Scheduler\Status\WorkerState;

/**
 * @package Zeus\Kernel\Scheduler
 */
class WorkerEvent extends SchedulerEvent
{
    const PRIORITY_FINALIZE = -100000;
    const PRIORITY_INITIALIZE = 50000;
    const PRIORITY_REGULAR = 0;

    const EVENT_EXIT = 'workerExit';

    const EVENT_RUNNING = 'workerRunning';
    const EVENT_WAITING = 'workerWaiting';

    const EVENT_TERMINATED = 'workerTerminated';

    /** @var WorkerState */
    private $worker;

    public function getWorker() : WorkerState
    {
        return $this->worker;
    }

    public function setWorker(WorkerState $worker)
    {
        $this->worker = $worker;
    }
}