<?php

namespace Zeus\Kernel\ProcessManager;
use Zend\EventManager\Event;

/**
 * @package Zeus\Kernel\ProcessManager
 */
class WorkerEvent extends Event
{
    const PRIORITY_FINALIZE = -100000;
    const PRIORITY_INITIALIZE = 50000;
    const PRIORITY_REGULAR = 0;

    const EVENT_WORKER_MESSAGE = 'workerMessage';

    const EVENT_WORKER_INIT = 'workerStarted';
    const EVENT_WORKER_EXIT = 'workerExit';

    const EVENT_WORKER_LOOP = 'workerLoop';

    const EVENT_WORKER_RUNNING = 'workerRunning';
    const EVENT_WORKER_WAITING = 'workerWaiting';

    /** @var bool */
    protected $stopWorker = false;

    /**
     * @return Worker
     */
    public function getTarget()
    {
        return parent::getTarget();
    }

    /**
     * @param bool $stop
     * @return $this
     */
    public function stopWorker(bool $stop)
    {
        $this->stopWorker = $stop;

        return $this;
    }

    public function isWorkerStopping() : bool
    {
        return $this->stopWorker;
    }
}