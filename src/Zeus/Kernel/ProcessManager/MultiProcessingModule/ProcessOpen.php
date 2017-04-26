<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;
use Zeus\Kernel\ProcessManager\EventsInterface;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PosixProcessBridgeInterface;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

final class ProcessOpen implements MultiProcessingModuleInterface
{
    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

    /** @var SchedulerEvent */
    protected $event;

    /** @var SchedulerEvent */
    protected $processEvent;

    /** @var PosixProcessBridgeInterface */
    protected static $pcntlBridge;

    protected $processes;

    /**
     * PosixDriver constructor.
     */
    public function __construct($schedulerEvent)
    {
        $this->event = $schedulerEvent;
        $this->ppid = getmypid();
    }

    /**
     * @return PosixProcessBridgeInterface
     */
    private static function getPcntlBridge()
    {
        if (!isset(static::$pcntlBridge)) {
            static::$pcntlBridge = new PcntlBridge();
        }

        return static::$pcntlBridge;
    }

    /**
     * @param PosixProcessBridgeInterface $bridge
     */
    public static function setPcntlBridge(PosixProcessBridgeInterface $bridge)
    {
        static::$pcntlBridge = $bridge;
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function attach(EventManagerInterface $events)
    {
        $events->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, [$this, 'onKernelStart'], -9000);
        $events->attach(SchedulerEvent::EVENT_PROCESS_CREATE, [$this, 'onProcessCreate'], -9000);
        $events->attach(SchedulerEvent::EVENT_PROCESS_INIT, [$this, 'onProcessInit'], -9000);
        $events->attach(SchedulerEvent::EVENT_PROCESS_WAITING, [$this, 'onProcessWaiting'], -9000);
        $events->attach(SchedulerEvent::EVENT_PROCESS_TERMINATE, [$this, 'onProcessTerminate'], -9000);
        $events->attach(SchedulerEvent::EVENT_PROCESS_LOOP, [$this, 'onProcessLoop'], -9000);
        $events->attach(SchedulerEvent::EVENT_PROCESS_RUNNING, [$this, 'onProcessRunning'], -9000);
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerInit'], -9000);
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'onSchedulerStop'], -9000);
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, [$this, 'onSchedulerLoop'], -9000);

        $this->events = $events;

        return $this;
    }

    /**
     * @param bool $throwException
     * @return bool
     * @throws \Exception
     */
    public static function isSupported($throwException = false)
    {
        return function_exists('proc_open');
    }


    /**
     * @param EventInterface $event
     */
    public function onProcessTerminate(EventInterface $event)
    {
        $this->terminateProcess($event->getParam('uid'), $event->getParam('soft', false));
    }

    /**
     *
     */
    public function onKernelStart()
    {
    }

    public function onSchedulerTerminate()
    {
        $event = $this->event;
        $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
        $event->setParam('uid', getmypid());
        $event->stopPropagation(false);
        $this->events->triggerEvent($event);
    }

    public function onProcessRunning()
    {
        $this->getPcntlBridge()->pcntlSigprocmask(SIG_BLOCK, [SIGTERM]);
    }

    public function onProcessLoop()
    {
        $this->getPcntlBridge()->pcntlSignalDispatch();
    }

    public function onProcessWaiting()
    {
        $this->getPcntlBridge()->pcntlSigprocmask(SIG_UNBLOCK, [SIGTERM]);
        $this->onProcessLoop();
    }

    public function onSchedulerLoop()
    {
        // catch other potential signals to avoid race conditions
        while (($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
            $event = $this->event;
            $event->setName(SchedulerEvent::EVENT_PROCESS_TERMINATED);
            $event->setParam('uid', $pid);
            $event->stopPropagation(false);
            $this->events->triggerEvent($event);
        }

        $this->onProcessLoop();

        if ($this->ppid !== $this->getPcntlBridge()->posixGetPpid()) {
            $event = $this->event;
            $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
            $event->setParam('uid', $this->ppid);
            $event->stopPropagation(false);
            $this->events->triggerEvent($event);
        }
    }

    public function onProcessInit()
    {
        $pcntl = $this->getPcntlBridge();
        // we are the new process
        $pcntl->pcntlSignal(SIGTERM, SIG_DFL);
        $pcntl->pcntlSignal(SIGINT, SIG_DFL);
        $pcntl->pcntlSignal(SIGHUP, SIG_DFL);
        $pcntl->pcntlSignal(SIGQUIT, SIG_DFL);
        $pcntl->pcntlSignal(SIGTSTP, SIG_DFL);
    }

    public function onSchedulerStop()
    {
        $this->getPcntlBridge()->pcntlWait($status, WUNTRACED);
        $this->onProcessLoop();
    }

    protected function startProcess(SchedulerEvent $event)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/tmp/out.log', 'w'],
            2 => ['file', '/tmp/error.log', 'w'],
        ];

        $pipes = [];

        $phpExecutable = $_SERVER['_'];

        $applicationPath = $_SERVER['PHP_SELF'];

        $type = $event->getParam('server') ? 'scheduler' : 'process';

        $command = sprintf("%s %s zeus %s %s", $phpExecutable, $applicationPath, $type, $event->getScheduler()->getConfig()->getServiceName());

        $process = proc_open($command, $descriptors, $pipes, getcwd());
        if ($process === false) {
            throw new ProcessManagerException("Could not create a descendant process", ProcessManagerException::PROCESS_NOT_CREATED);
        }

        return $process;
    }

    public function onProcessCreate(SchedulerEvent $event)
    {
        $process = $this->startProcess($event);

        $status = proc_get_status($process);
        $pid = $status['pid'];
        $this->processes[$pid] = $process;

        // we are the parent
        $eventName = SchedulerEvent::EVENT_PROCESS_CREATED;
        $event->setParam('uid', $pid);
        $event->setName($eventName);
        $event->stopPropagation(false);
        $this->events->triggerEvent($event);
    }

    public function onSchedulerInit()
    {
        $pcntl = $this->getPcntlBridge();
        $onTaskTerminate = function() { $this->onSchedulerTerminate(); };
        //pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD]);
        $pcntl->pcntlSignal(SIGTERM, $onTaskTerminate);
        $pcntl->pcntlSignal(SIGQUIT, $onTaskTerminate);
        $pcntl->pcntlSignal(SIGTSTP, $onTaskTerminate);
        $pcntl->pcntlSignal(SIGINT, $onTaskTerminate);
        $pcntl->pcntlSignal(SIGHUP, $onTaskTerminate);
    }

    /**
     * @param int $pid
     * @param bool $useSoftTermination
     * @return $this
     */
    protected function terminateProcess($pid, $useSoftTermination)
    {
        $this->getPcntlBridge()->posixKill($pid, $useSoftTermination ? SIGINT : SIGKILL);

        proc_close($this->processes[$pid]);

        return $this;
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