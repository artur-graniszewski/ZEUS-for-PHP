<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;

final class PosixProcess extends AbstractProcessModule implements MultiProcessingModuleInterface, SeparateAddressSpaceInterface, SharedInitialAddressSpaceInterface
{
    /** @var int Parent PID */
    public $ppid;

    /**
     * PosixProcess constructor.
     */
    public function __construct()
    {
        $this->ppid = getmypid();

        parent::__construct();
    }

    public static function isSupported(& $errorMessage  = '') : bool
    {
        $bridge = static::getPcntlBridge();

        if (!$bridge->isSupported()) {
            $className = basename(str_replace('\\', '/', static::class));

            $errorMessage = sprintf("PCNTL extension is required by %s but disabled in PHP", $className);

            return false;
        }

        return true;
    }

    public function onKernelStart(SchedulerEvent $event)
    {
        // make the current process a session leader
        $this->getPcntlBridge()->posixSetSid();

        parent::onKernelStart($event);
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
        $this->getPcntlBridge()->pcntlSignalDispatch();

        if ($this->ppid !== $this->getPcntlBridge()->posixGetPpid()) {
            $this->setIsTerminating(true);
        }

        parent::onWorkerLoop($event);
    }

    public function onSchedulerStop(SchedulerEvent $event)
    {
        $this->getPcntlBridge()->pcntlWait($status, WUNTRACED);
        $this->getPcntlBridge()->pcntlSignalDispatch();
    }

    protected function createProcess(WorkerEvent $event): int
    {
        $pcntl = $this->getPcntlBridge();
        $pid = $pcntl->pcntlFork();

        switch ($pid) {
            case -1:
                throw new SchedulerException("Could not create a descendant process", SchedulerException::WORKER_NOT_STARTED);
            case 0:
                // we are the new process
                $this->ppid = $this->getPcntlBridge()->posixGetPpid();

                $onTerminate = function() { $this->setIsTerminating(true); };
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

        return $pid;
    }

    /**
     * @return $this
     */
    protected function checkWorkers()
    {
        while (($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
            $this->raiseWorkerExitedEvent($pid, $pid, 1);
        }

        parent::checkWorkers();

        return $this;
    }
}