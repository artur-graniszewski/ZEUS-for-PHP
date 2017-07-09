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

    /**
     * @return Worker
     */
    public function getTarget()
    {
        return parent::getTarget();
    }
}