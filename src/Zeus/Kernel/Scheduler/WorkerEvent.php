<?php

namespace Zeus\Kernel\Scheduler;

/**
 * @package Zeus\Kernel\Scheduler
 */
class WorkerEvent extends SchedulerEvent
{
    const PRIORITY_FINALIZE = -100000;
    const PRIORITY_INITIALIZE = 50000;
    const PRIORITY_REGULAR = 0;

    const EVENT_WORKER_MESSAGE = 'workerMessage';

    const EVENT_WORKER_CREATE = 'workerCreate';
    const EVENT_WORKER_INIT = 'workerStarted';
    const EVENT_WORKER_EXIT = 'workerExit';

    const EVENT_WORKER_LOOP = 'workerLoop';

    const EVENT_WORKER_RUNNING = 'workerRunning';
    const EVENT_WORKER_WAITING = 'workerWaiting';

    const EVENT_WORKER_TERMINATED = 'workerTerminated';

    /** @var bool */
    private $stopWorker = false;

    /** @var Worker */
    private $worker;

    /**
     * @return Worker
     */
    public function getWorker() : Worker
    {
        return $this->worker;
    }

    public function setWorker(Worker $worker)
    {
        $this->worker = $worker;
    }

    /**
     * @param bool $stop
     */
    public function stopWorker(bool $stop)
    {
        $this->stopWorker = $stop;
    }

    public function isWorkerStopping() : bool
    {
        return $this->stopWorker;
    }
}