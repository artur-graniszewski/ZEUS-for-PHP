<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PosixProcessBridgeInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;

final class PosixProcess extends AbstractModule implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface, SharedInitialAddressSpaceInterface
{
    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

    /** @var PosixProcessBridgeInterface */
    protected static $pcntlBridge;

    /**
     * PosixDriver constructor.
     */
    public function __construct()
    {
        $this->ppid = getmypid();

        parent::__construct();
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
     * @param EventManagerInterface $eventManager
     * @return $this
     */
    public function attach(EventManagerInterface $eventManager)
    {
        $this->events = $eventManager;

        $sharedEventManager = $eventManager->getSharedManager();
        $sharedEventManager->attach('*', SchedulerEvent::INTERNAL_EVENT_KERNEL_START, [$this, 'onKernelStart']);
        $sharedEventManager->attach('*', WorkerEvent::EVENT_WORKER_CREATE, [$this, 'onProcessCreate'], 1000);
        $sharedEventManager->attach('*', WorkerEvent::EVENT_WORKER_WAITING, [$this, 'onProcessWaiting']);
        $sharedEventManager->attach('*', SchedulerEvent::EVENT_WORKER_TERMINATE, [$this, 'onProcessTerminate']);
        $sharedEventManager->attach('*', WorkerEvent::EVENT_WORKER_LOOP, [$this, 'onProcessLoop']);
        $sharedEventManager->attach('*', WorkerEvent::EVENT_WORKER_RUNNING, [$this, 'onProcessRunning']);
        $sharedEventManager->attach('*', SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerInit']);
        $sharedEventManager->attach('*', SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'onSchedulerStop'], -9999);
        $sharedEventManager->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, [$this, 'onSchedulerLoop']);

        return $this;
    }

    /**
     * @param bool $throwException
     * @return bool
     * @throws \Throwable
     */
    public static function isSupported($throwException = false)
    {
        $bridge = static::getPcntlBridge();

        try {
            $bridge->isSupported();
        } catch (\Throwable $e) {
            if ($throwException) {
                throw $e;
            }

            return false;
        }

        return true;
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
        // make the current process a session leader
        $this->getPcntlBridge()->posixSetSid();
    }

    public function onSchedulerTerminate()
    {
        $event = new SchedulerEvent();
        $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
        $event->setParam('uid', getmypid());
        $event->setParam('processId', getmypid());
        $event->setParam('threadId', 1);
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

    public function onSchedulerLoop(SchedulerEvent $oldEvent)
    {
        // catch other potential signals to avoid race conditions
        while (($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
            $event = new SchedulerEvent();
            $event->setName(SchedulerEvent::EVENT_WORKER_TERMINATED);
            $event->setParam('uid', $pid);
            $event->setParam('threadId', $oldEvent->getParam('threadId'));
            $event->setParam('processId', $pid);
            $this->events->triggerEvent($event);
        }

        $this->onProcessLoop();

        if ($this->ppid !== $this->getPcntlBridge()->posixGetPpid()) {
            $event = new SchedulerEvent();
            $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
            $event->setParam('uid', $this->ppid);
            $event->setParam('threadId', 1);
            $event->setParam('processId', $this->ppid);
            $this->events->triggerEvent($event);
        }
    }

    public function onSchedulerStop()
    {
        $this->getPcntlBridge()->pcntlWait($status, WUNTRACED);
        $this->onProcessLoop();
    }

    public function onProcessCreate(SchedulerEvent $event)
    {
        $pcntl = $this->getPcntlBridge();
        $pid = $pcntl->pcntlFork();

        switch ($pid) {
            case -1:
                throw new SchedulerException("Could not create a descendant process", SchedulerException::WORKER_NOT_STARTED);
            case 0:
                // we are the new process
                $pcntl->pcntlSignal(SIGTERM, SIG_DFL);
                $pcntl->pcntlSignal(SIGINT, SIG_DFL);
                $pcntl->pcntlSignal(SIGHUP, SIG_DFL);
                $pcntl->pcntlSignal(SIGQUIT, SIG_DFL);
                $pcntl->pcntlSignal(SIGTSTP, SIG_DFL);
                $pid = getmypid();
                $event->setParam('init_process', true);
                break;
            default:
                // we are the parent
                $event->setParam('init_process', false);
                break;
        }

        $event->setParam('uid', $pid);
        $event->setParam('processId', $pid);
        $event->setParam('threadId', 1);
    }

    public function onSchedulerInit()
    {
        $pcntl = $this->getPcntlBridge();
        $onTerminate = function() { $this->onSchedulerTerminate(); };
        $pcntl->pcntlSignal(SIGTERM, $onTerminate);
        $pcntl->pcntlSignal(SIGQUIT, $onTerminate);
        $pcntl->pcntlSignal(SIGTSTP, $onTerminate);
        $pcntl->pcntlSignal(SIGINT, $onTerminate);
        $pcntl->pcntlSignal(SIGHUP, $onTerminate);
    }

    /**
     * @param int $pid
     * @param bool $useSoftTermination
     * @return $this
     */
    protected function terminateProcess($pid, $useSoftTermination)
    {
        $this->getPcntlBridge()->posixKill($pid, $useSoftTermination ? SIGINT : SIGKILL);

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