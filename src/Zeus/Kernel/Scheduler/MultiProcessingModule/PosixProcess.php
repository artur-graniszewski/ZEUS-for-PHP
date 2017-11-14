<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zend\EventManager\EventInterface;
use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;

final class PosixProcess extends AbstractProcessModule implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface, SharedInitialAddressSpaceInterface
{
    /** @var EventManagerInterface */
    protected $events;

    /** @var int Parent PID */
    public $ppid;

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
     * @param EventManagerInterface $eventManager
     * @return $this
     */
    public function attach(EventManagerInterface $eventManager)
    {
        parent::attach($eventManager);

        $eventManager->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_START, [$this, 'onKernelStart']);
//        $eventManager->attach(WorkerEvent::EVENT_WORKER_CREATE, [$this, 'onWorkerCreate'], 1000);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_WAITING, [$this, 'onProcessWaiting']);
        //$eventManager->attach(SchedulerEvent::EVENT_WORKER_TERMINATE, [$this, 'onWorkerTerminate']);
        $eventManager->attach(WorkerEvent::EVENT_WORKER_RUNNING, [$this, 'onWorkerRunning']);
        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_START, [$this, 'onSchedulerInit']);
        $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_STOP, [$this, 'onSchedulerStop'], -9999);

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

//    public function onWorkerTerminating()
//    {
//        $this->isWorkerTerminating = true;
//    }

    public function onWorkerRunning()
    {
        $this->getPcntlBridge()->pcntlSigprocmask(SIG_BLOCK, [SIGTERM]);
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
        $this->getPcntlBridge()->pcntlSignalDispatch();

        parent::onWorkerLoop($event);
    }

    public function onProcessWaiting(WorkerEvent $event)
    {
        $this->getPcntlBridge()->pcntlSigprocmask(SIG_UNBLOCK, [SIGTERM]);
        $this->getPcntlBridge()->pcntlSignalDispatch();
    }

    /**
     * @return $this
     */
    public function checkWorkers()
    {
        while (($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
            $this->raiseWorkerExitedEvent($pid, $pid, 1);
        }

        parent::checkWorkers();

        if ($this->ppid !== $this->getPcntlBridge()->posixGetPpid()) {
            $event = $this->getSchedulerEvent();
            $event->setName(SchedulerEvent::EVENT_SCHEDULER_STOP);
            $event->setParam('uid', $this->ppid);
            $event->setParam('threadId', 1);
            $event->setParam('processId', $this->ppid);
            $this->events->triggerEvent($event);
        }

        return $this;
    }


    public function onSchedulerStop()
    {
        $this->getPcntlBridge()->pcntlWait($status, WUNTRACED);
        $this->getPcntlBridge()->pcntlSignalDispatch();
    }

    public function onWorkerCreate(WorkerEvent $event)
    {
        $pipe = $this->createPipe();
        $event->setParam('connectionPort', $pipe->getLocalPort());

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
                $this->registerWorker($pid, $pipe);
                break;
        }

        $event->setParam('uid', $pid);
        $event->setParam('processId', $pid);
        $event->setParam('threadId', 1);
        $event->getWorker()->setProcessId($pid);
        $event->getWorker()->setThreadId(1);
        $event->getWorker()->setUid($pid);
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
     * @return MultiProcessingModuleCapabilities
     */
    public function getCapabilities()
    {
        $capabilities = new MultiProcessingModuleCapabilities();
        $capabilities->setIsolationLevel($capabilities::ISOLATION_PROCESS);

        return $capabilities;
    }
}