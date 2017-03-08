<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;
use Zeus\Kernel\ProcessManager\EventsInterface;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

final class PosixProcess implements MultiProcessingModuleInterface
{
    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

    /** @var SchedulerEvent */
    protected $schedulerEvent;

    /** @var SchedulerEvent */
    protected $processEvent;

    /**
     * PosixDriver constructor.
     */
    public function __construct($schedulerEvent, $processEvent)
    {
        $this->schedulerEvent = $schedulerEvent;
        $this->processEvent = $processEvent;
        $this->checkSetup();
        $this->ppid = getmypid();
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
<<<<<<< HEAD
        $events->attach(SchedulerEvent::EVENT_PROCESS_CREATE, [$this, 'startTask']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_WAITING, [$this, 'sigUnblock']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_TERMINATE, [$this, 'onProcessTerminate']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_LOOP, [$this, 'sigDispatch']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_RUNNING, [$this, 'sigBlock']);
        $events->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, [$this, 'onServerInit']);
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerInit']);
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'shutdownServer'], -9999);
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, [$this, 'processSignals']);
=======
        $events->attach(SchedulerEvent::PROCESS_CREATE, [$this, 'startTask']);
        $events->attach(SchedulerEvent::PROCESS_WAITING, [$this, 'sigUnblock']);
        $events->attach(SchedulerEvent::PROCESS_TERMINATE, [$this, 'onProcessTerminate']);
        $events->attach(SchedulerEvent::PROCESS_LOOP, [$this, 'sigDispatch']);
        $events->attach(SchedulerEvent::PROCESS_RUNNING, [$this, 'sigBlock']);
        $events->attach(SchedulerEvent::SERVER_START, [$this, 'onServerInit']);
        $events->attach(SchedulerEvent::SCHEDULER_START, [$this, 'onSchedulerInit']);
        $events->attach(SchedulerEvent::SCHEDULER_STOP, [$this, 'shutdownServer'], -9999);
        $events->attach(SchedulerEvent::SCHEDULER_LOOP, [$this, 'processSignals']);
>>>>>>> 62bb26e12691695d3208bff4dc2497dcae70eb26

        $this->events = $events;

        return $this;
    }

    /**
     * @return $this
     */
    private function checkSetup()
    {
        $className = basename(str_replace('\\', '/', get_class($this)));

        if (!extension_loaded('pcntl')) {
            throw new \RuntimeException(sprintf("PCNTL extension is required by %s but disabled in PHP",
                    $className
                )
            );
        }

        $requiredFunctions = [
            'pcntl_signal',
            'pcntl_sigprocmask',
            'pcntl_signal_dispatch',
            'pcntl_wifexited',
            'pcntl_wait',
            'posix_getppid',
            'posix_kill'
        ];

        $missingFunctions = [];

        foreach ($requiredFunctions as $function) {
            if (!is_callable($function)) {
                $missingFunctions[] = $function;
            }
        }

        if ($missingFunctions) {
            throw new \RuntimeException(sprintf("Following functions are required by %s but disabled in PHP: %s",
                    $className,
                    implode(", ", $missingFunctions)
                )
            );
        }

        return $this;
    }

    /**
     * @param EventInterface $event
     */
    public function onProcessTerminate(EventInterface $event)
    {
        $this->terminateTask($event->getParam('uid'), $event->getParam('soft'));
    }

    /**
     *
     */
    public function onServerInit()
    {
        // make the current process a session leader
        posix_setsid();
    }

    public function onSchedulerTerminate()
    {
<<<<<<< HEAD
        $this->events->trigger(SchedulerEvent::EVENT_SCHEDULER_STOP, null, ['uid' => getmypid()]);
=======
        $this->events->trigger(SchedulerEvent::SCHEDULER_STOP, null, ['uid' => getmypid()]);
>>>>>>> 62bb26e12691695d3208bff4dc2497dcae70eb26
    }

    public function sigBlock()
    {
        pcntl_sigprocmask(SIG_BLOCK, [SIGTERM]);
    }

    public function sigDispatch()
    {
        pcntl_signal_dispatch();
    }

    public function sigUnblock()
    {
        pcntl_sigprocmask(SIG_UNBLOCK, [SIGTERM]);
        $this->sigDispatch();
    }

    public function processSignals()
    {
        // catch other potential signals to avoid race conditions
        while (($pid = pcntl_wait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
<<<<<<< HEAD
            $eventType = $pid === getmypid() ? SchedulerEvent::EVENT_SCHEDULER_STOP : SchedulerEvent::EVENT_PROCESS_TERMINATED;
=======
            $eventType = $pid === getmypid() ? SchedulerEvent::SCHEDULER_STOP : SchedulerEvent::PROCESS_TERMINATED;
>>>>>>> 62bb26e12691695d3208bff4dc2497dcae70eb26
            $this->events->trigger($eventType, null, ['uid' => $pid]);
        }

        $this->sigDispatch();

        if ($this->ppid !== posix_getppid()) {
<<<<<<< HEAD
            $this->events->trigger(SchedulerEvent::EVENT_SCHEDULER_STOP, null, ['uid' => $this->ppid]);
=======
            $this->events->trigger(SchedulerEvent::SCHEDULER_STOP, null, ['uid' => $this->ppid]);
>>>>>>> 62bb26e12691695d3208bff4dc2497dcae70eb26
        }
    }

    public function shutdownServer()
    {
        pcntl_wait($status, WUNTRACED);
        $this->sigDispatch();
    }

    public function startTask(EventInterface $event)
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new ProcessManagerException("Could not create a descendant process", ProcessManagerException::PROCESS_NOT_CREATED);
        } else if ($pid) {
            // we are the parent
            $event->setParam('uid', $pid);
<<<<<<< HEAD
            $this->events->trigger(SchedulerEvent::EVENT_PROCESS_CREATED, null, ['uid' => $pid, 'server' => $event->getParam('server')]);
=======
            $this->events->trigger(SchedulerEvent::PROCESS_CREATED, null, ['uid' => $pid, 'server' => $event->getParam('server')]);
>>>>>>> 62bb26e12691695d3208bff4dc2497dcae70eb26

            return $this;
        } else {
            $pid = getmypid();
        }

        // we are the new process
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_signal(SIGHUP, SIG_DFL);
        pcntl_signal(SIGQUIT, SIG_DFL);
        pcntl_signal(SIGTSTP, SIG_DFL);

        $event->setParam('uid', $pid);
        $processEvent = $this->processEvent;
<<<<<<< HEAD
        $processEvent->setName(SchedulerEvent::EVENT_PROCESS_INIT);
=======
        $processEvent->setName(SchedulerEvent::PROCESS_INIT);
>>>>>>> 62bb26e12691695d3208bff4dc2497dcae70eb26
        $processEvent->setParams($event->getParams());
        $this->events->triggerEvent($processEvent);

        return $this;
    }

    public function onSchedulerInit()
    {
        $onTaskTerminate = function() { $this->onSchedulerTerminate(); };
        //pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD]);
        pcntl_signal(SIGTERM, $onTaskTerminate);
        pcntl_signal(SIGQUIT, $onTaskTerminate);
        pcntl_signal(SIGTSTP, $onTaskTerminate);
        pcntl_signal(SIGINT, $onTaskTerminate);
        pcntl_signal(SIGHUP, $onTaskTerminate);
    }

    /**
     * @param int $pid
     * @param bool|false $useSoftTermination
     * @return $this
     */
    protected function terminateTask($pid, $useSoftTermination = false)
    {
        posix_kill($pid, $useSoftTermination ? SIGINT : SIGKILL);

        return $this;
    }

    /**
     * @param bool|false $useSoftTermination
     * @return $this
     */
    public function terminateAllTasks($useSoftTermination = false)
    {
        return $this->terminateTask(0, $useSoftTermination);
    }

    /**
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities()
    {
        $capabilities = new MultiProcessingModuleCapabilities();
        $capabilities->setIsolationLevel($capabilities::ISOLATION_PROCESS);

        return $capabilities;
    }
}