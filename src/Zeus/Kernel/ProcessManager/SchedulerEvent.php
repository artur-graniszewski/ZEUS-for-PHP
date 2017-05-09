<?php

namespace Zeus\Kernel\ProcessManager;
use Zend\EventManager\Event;

/**
 * @package Zeus\Kernel\ProcessManager
 */
class SchedulerEvent extends Event
{
    const PRIORITY_FINALIZE = -100000;
    const PRIORITY_INITIALIZE = 50000;
    const PRIORITY_REGULAR = 0;

    const EVENT_PROCESS_CREATE = 'processCreate';
    const EVENT_PROCESS_CREATED = 'processCreated';

    const EVENT_PROCESS_MESSAGE = 'processMessage';

    const EVENT_PROCESS_INIT = 'processStarted';
    const EVENT_PROCESS_TERMINATED = 'processTerminated';
    const EVENT_PROCESS_TERMINATE = 'processTerminate';
    const EVENT_PROCESS_EXIT = 'processExit';

    const EVENT_PROCESS_LOOP = 'processLoop';

    const EVENT_PROCESS_RUNNING = 'processRunning';
    const EVENT_PROCESS_WAITING = 'processWaiting';

    const EVENT_SCHEDULER_START = 'schedulerStart';
    const EVENT_SCHEDULER_STOP = 'schedulerStop';
    const EVENT_SCHEDULER_LOOP = 'schedulerLoop';

    // WARNING: the following INTERNAL_* events should not be used in custom projects
    // and if used - are subjects to change and BC breaks.
    const INTERNAL_EVENT_KERNEL_START = 'serverStart';
    const INTERNAL_EVENT_KERNEL_STOP = 'serverStop';

    /** @var Scheduler */
    protected $scheduler;

    /** @var Process */
    protected $process;

    /**
     * @return Scheduler
     */
    public function getScheduler()
    {
        return $this->scheduler;
    }

    /**
     * @param Scheduler $scheduler
     * @return $this
     */
    public function setScheduler(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
        $this->setParam('scheduler', $scheduler);

        return $this;
    }

    /**
     * @return Process
     */
    public function getProcess()
    {
        return $this->process;
    }

    /**
     * @param Process $process
     * @return $this
     */
    public function setProcess(Process $process)
    {
        $this->process = $process;
        $this->setParam('process', $process);

        return $this;
    }
}