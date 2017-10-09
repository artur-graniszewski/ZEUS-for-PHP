<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PcntlBridge;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess\PosixProcessBridgeInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Worker;
use Zeus\Kernel\Scheduler\WorkerEvent;

final class PosixProcess extends AbstractModule implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface, SharedInitialAddressSpaceInterface
{
    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

    /** @var PosixProcessBridgeInterface */
    protected static $pcntlBridge;

    /** @var bool */
    protected $isWorkerTerminating = false;

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
        $sharedEventManager->attach('*', WorkerEvent::EVENT_WORKER_CREATE, [$this, 'onWorkerCreate'], 1000);
        $sharedEventManager->attach('*', WorkerEvent::EVENT_WORKER_WAITING, [$this, 'onProcessWaiting']);
        $sharedEventManager->attach('*', SchedulerEvent::EVENT_WORKER_TERMINATE, [$this, 'onWorkerTerminate']);
        $sharedEventManager->attach('*', WorkerEvent::EVENT_WORKER_LOOP, [$this, 'onWorkerLoop'], WorkerEvent::PRIORITY_INITIALIZE);
        $sharedEventManager->attach('*', WorkerEvent::EVENT_WORKER_RUNNING, [$this, 'onWorkerRunning']);
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
    public function onWorkerTerminate(EventInterface $event)
    {
        $this->terminateWorker($event->getParam('uid'), $event->getParam('soft', false));
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
        $event = $this->getSchedulerEvent();
        $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
        $event->setParam('uid', getmypid());
        $event->setParam('processId', getmypid());
        $event->setParam('threadId', 1);
        $this->events->triggerEvent($event);
    }

    public function onWorkerTerminating()
    {
        $this->isWorkerTerminating = true;
    }

    public function onWorkerRunning()
    {
        $this->getPcntlBridge()->pcntlSigprocmask(SIG_BLOCK, [SIGTERM]);
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
        $this->getPcntlBridge()->pcntlSignalDispatch();

        if ($this->isWorkerTerminating) {
            $event->stopWorker(true);
            $event->stopPropagation(true);
        }
    }

    public function onProcessWaiting(WorkerEvent $event)
    {
        $this->getPcntlBridge()->pcntlSigprocmask(SIG_UNBLOCK, [SIGTERM]);
        $this->getPcntlBridge()->pcntlSignalDispatch();
    }

    public function onSchedulerLoop(SchedulerEvent $oldEvent)
    {
        // catch other potential signals to avoid race conditions
        while (($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
            $event = $this->getSchedulerEvent();
            $event->setName(SchedulerEvent::EVENT_WORKER_TERMINATED);
            $event->setParam('uid', $pid);
            $event->setParam('threadId', $oldEvent->getParam('threadId'));
            $event->setParam('processId', $pid);
            $this->events->triggerEvent($event);
        }

        $this->getPcntlBridge()->pcntlSignalDispatch();

        if ($this->ppid !== $this->getPcntlBridge()->posixGetPpid()) {
            $event = $this->getSchedulerEvent();
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
        $this->getPcntlBridge()->pcntlSignalDispatch();
    }

    public function onWorkerCreate(SchedulerEvent $event)
    {
        $pcntl = $this->getPcntlBridge();
        $pid = $pcntl->pcntlFork();

        switch ($pid) {
            case -1:
                throw new SchedulerException("Could not create a descendant process", SchedulerException::WORKER_NOT_STARTED);
            case 0:
                // we are the new process
                $onTerminate = function() { $this->onWorkerTerminating(); };
                $pcntl->pcntlSignal(SIGTERM, $onTerminate);
                $pcntl->pcntlSignal(SIGQUIT, $onTerminate);
                $pcntl->pcntlSignal(SIGTSTP, $onTerminate);
                $pcntl->pcntlSignal(SIGINT, $onTerminate);
                $pcntl->pcntlSignal(SIGHUP, $onTerminate);
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
    protected function terminateWorker($pid, $useSoftTermination)
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