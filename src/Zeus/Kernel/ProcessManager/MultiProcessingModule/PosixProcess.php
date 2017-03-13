<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\ProcessManager\Exception\ProcessManagerException;
use Zeus\Kernel\ProcessManager\EventsInterface;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess\PosixProcessBridgeInterface;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

final class PosixProcess implements MultiProcessingModuleInterface
{
    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

    /** @var SchedulerEvent */
    protected $event;

    /** @var SchedulerEvent */
    protected $processEvent;

    /** @var bool|null */
    private static $isPcntlExtensionLoaded = null;

    /** @var PosixProcessBridgeInterface */
    protected static $pcntlBridge;

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
        $events->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, [$this, 'onKernelStart']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_CREATE, [$this, 'onProcessCreate']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_WAITING, [$this, 'onProcessWaiting']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_TERMINATE, [$this, 'onProcessTerminate']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_LOOP, [$this, 'onProcessLoop']);
        $events->attach(SchedulerEvent::EVENT_PROCESS_RUNNING, [$this, 'onProcessRunning']);
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerInit']);
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'onSchedulerStop'], -9999);
        $events->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, [$this, 'onSchedulerLoop']);

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
        $bridge = static::getPcntlBridge();

        try {
            $bridge->isSupported();
        } catch (\Exception $e) {
            if ($throwException) {
                throw $e;
            } else {
                return false;
            }
        }

        return true;
    }


    /**
     * @param EventInterface $event
     */
    public function onProcessTerminate(EventInterface $event)
    {
        $this->terminateProcess($event->getParam('uid'), $event->getParam('soft'));
    }

    /**
     *
     */
    public function onKernelStart()
    {
        // make the current process a session leader
        $this->getPcntlBridge()->posixSetSid();
    }

    public function onSchedulerTerminate()
    {
        $event = $this->event;
        $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
        $event->setParam('uid', getmypid());
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
            $this->events->triggerEvent($event);
        }

        $this->onProcessLoop();

        if ($this->ppid !== $this->getPcntlBridge()->posixGetPpid()) {
            $event = $this->event;
            $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
            $event->setParam('uid', $this->ppid);
            $this->events->triggerEvent($event);
        }
    }

    public function onSchedulerStop()
    {
        $this->getPcntlBridge()->pcntlWait($status, WUNTRACED);
        $this->onProcessLoop();
    }

    public function onProcessCreate(EventInterface $event)
    {
        $pcntl = $this->getPcntlBridge();
        $pid = $pcntl->pcntlFork();

        if ($pid === -1) {
            throw new ProcessManagerException("Could not create a descendant process", ProcessManagerException::PROCESS_NOT_CREATED);
        } else if ($pid) {
            // we are the parent
            $event->setParam('uid', $pid);
            $processEvent = $this->event;
            $processEvent->setName(SchedulerEvent::EVENT_PROCESS_CREATED);
            $params = $event->getParams();
            $params['uid'] = $pid;
            $params['server'] = $event->getParam('server');
            $processEvent->setParams($params);
            $this->events->triggerEvent($processEvent);

            return;
        } else {
            $pid = getmypid();
        }

        // we are the new process
        $pcntl->pcntlSignal(SIGTERM, SIG_DFL);
        $pcntl->pcntlSignal(SIGINT, SIG_DFL);
        $pcntl->pcntlSignal(SIGHUP, SIG_DFL);
        $pcntl->pcntlSignal(SIGQUIT, SIG_DFL);
        $pcntl->pcntlSignal(SIGTSTP, SIG_DFL);

        $event->setParam('uid', $pid);
        $processEvent = $this->event;

        $processEvent->setName(SchedulerEvent::EVENT_PROCESS_INIT);
        $processEvent->setParams($event->getParams());
        $this->events->triggerEvent($processEvent);
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
     * @param bool|false $useSoftTermination
     * @return $this
     */
    protected function terminateProcess($pid, $useSoftTermination = false)
    {
        $this->getPcntlBridge()->posixKill($pid, $useSoftTermination ? SIGINT : SIGKILL);

        return $this;
    }

    /**
     * @param bool|false $useSoftTermination
     * @return $this
     */
    public function terminateAllProcesses($useSoftTermination = false)
    {
        return $this->terminateProcess(0, $useSoftTermination);
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