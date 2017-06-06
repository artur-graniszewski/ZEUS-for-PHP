<?php

namespace Zeus\Kernel\ProcessManager;
use Zend\EventManager\Event;

/**
 * @package Zeus\Kernel\ProcessManager
 */
class ProcessEvent extends Event
{
    const PRIORITY_FINALIZE = -100000;
    const PRIORITY_INITIALIZE = 50000;
    const PRIORITY_REGULAR = 0;

    const EVENT_PROCESS_MESSAGE = 'processMessage';

    const EVENT_PROCESS_INIT = 'processStarted';
    const EVENT_PROCESS_TERMINATED = 'processTerminated';
    const EVENT_PROCESS_TERMINATE = 'processTerminate';
    const EVENT_PROCESS_EXIT = 'processExit';

    const EVENT_PROCESS_LOOP = 'processLoop';

    const EVENT_PROCESS_RUNNING = 'processRunning';
    const EVENT_PROCESS_WAITING = 'processWaiting';

    /**
     * @return Process
     */
    public function getTarget()
    {
        return parent::getTarget();
    }
}