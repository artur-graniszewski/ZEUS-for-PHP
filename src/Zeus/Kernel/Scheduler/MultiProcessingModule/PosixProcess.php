<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;

use function basename;
use function str_replace;
use function sprintf;
use function getmypid;

final class PosixProcess extends AbstractProcessModule implements SeparateAddressSpaceInterface, SharedInitialAddressSpaceInterface
{
    /** @var int Parent PID */
    private $ppid;

    public function __construct()
    {
        $this->ppid = getmypid();
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
        static::getPcntlBridge()->posixSetSid();
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
        static::getPcntlBridge()->pcntlSignalDispatch();

        if ($this->ppid !== $this->getPcntlBridge()->posixGetPpid()) {
            $this->getWrapper()->setIsTerminating(true);
        }
    }

    public function onSchedulerStop(SchedulerEvent $event)
    {
        $status = 0;
        static::getPcntlBridge()->pcntlWait($status, WUNTRACED);
        static::getPcntlBridge()->pcntlSignalDispatch();
    }

    public function onKernelStop(SchedulerEvent $event)
    {
        $status = 0;
        $this->getPcntlBridge()->pcntlSignalDispatch();
        $this->getPcntlBridge()->pcntlWait($status, WUNTRACED);
    }

    protected function createProcess(WorkerEvent $event) : int
    {
        $pcntl = static::getPcntlBridge();
        $pid = $pcntl->pcntlFork();

        switch ($pid) {
            case -1:
                throw new SchedulerException("Could not create a descendant process", SchedulerException::WORKER_NOT_STARTED);
            case 0:
                // we are the new process
                $this->ppid = $this->getPcntlBridge()->posixGetPpid();

                $onTerminate = function() { $this->getWrapper()->setIsTerminating(true); };
                $pcntl->pcntlSignal(SIGTERM, $onTerminate);
                $pcntl->pcntlSignal(SIGQUIT, $onTerminate);
                $pcntl->pcntlSignal(SIGTSTP, $onTerminate);
                $pcntl->pcntlSignal(SIGINT, $onTerminate);
                $pcntl->pcntlSignal(SIGHUP, $onTerminate);
                $pid = getmypid();
                $event->setParam(Scheduler::WORKER_INIT, true);
                break;
            default:
                // we are the parent
                $event->setParam(Scheduler::WORKER_INIT, false);
                break;
        }

        return $pid;
    }

    public function onWorkersCheck(SchedulerEvent $event)
    {
        $pcntlStatus = 0;
        while (($pid = static::getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
            $this->getWrapper()->raiseWorkerExitedEvent($pid, $pid, 1);
        }
    }

    public function onKernelLoop(SchedulerEvent $event)
    {
        $this->getPcntlBridge()->pcntlSignalDispatch();
    }

    public function onWorkerExit(WorkerEvent $event)
    {
        // TODO: Implement onWorkerExit() method.
    }

    public function onSchedulerInit(SchedulerEvent $event)
    {
        // TODO: Implement onSchedulerInit() method.
    }

    public function onWorkerTerminated(WorkerEvent $event)
    {
        // TODO: Implement onWorkerTerminated() method.
    }

    public function onSchedulerLoop(SchedulerEvent $event)
    {
        // TODO: Implement onSchedulerLoop() method.
    }
}