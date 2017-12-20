<?php

namespace Zeus\Kernel\Scheduler;

/**
 * @package Zeus\Kernel\Scheduler
 */
class WorkerEvent extends AbstractEvent
{
    const PRIORITY_FINALIZE = -100000;
    const PRIORITY_INITIALIZE = 50000;
    const PRIORITY_REGULAR = 0;

    const EVENT_CREATE = 'workerCreate';
    const EVENT_INIT = 'workerStarted';
    const EVENT_EXIT = 'workerExit';

    const EVENT_LOOP = 'workerLoop';

    const EVENT_RUNNING = 'workerRunning';
    const EVENT_WAITING = 'workerWaiting';

    const EVENT_TERMINATED = 'workerTerminated';
    const EVENT_TERMINATE = 'workerTerminate';

    /** @var Worker */
    private $worker;

    public function getWorker() : Worker
    {
        return $this->worker;
    }

    public function setWorker(Worker $worker)
    {
        $this->worker = $worker;
    }
}