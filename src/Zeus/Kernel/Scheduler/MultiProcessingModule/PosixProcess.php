<?php

namespace Zeus\Kernel\Scheduler\MultiProcessingModule;

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

    /**
     * PosixProcess constructor.
     */
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
        $this->getPcntlBridge()->posixSetSid();
    }

    public function onWorkerLoop(WorkerEvent $event)
    {
        $this->getPcntlBridge()->pcntlSignalDispatch();

        if ($this->ppid !== $this->getPcntlBridge()->posixGetPpid()) {
            $this->getWrapper()->setIsTerminating(true);
        }
    }

    public function onSchedulerStop(SchedulerEvent $event)
    {
        $status = 0;
        $this->getPcntlBridge()->pcntlWait($status, WUNTRACED);
        $this->getPcntlBridge()->pcntlSignalDispatch();
    }

    protected function createProcess(WorkerEvent $event) : int
    {
        $pcntl = $this->getPcntlBridge();
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
                $event->setParam('initWorker', true);
                break;
            default:
                // we are the parent
                $event->setParam('initWorker', false);
                break;
        }

        return $pid;
    }

    public function onWorkersCheck(SchedulerEvent $event)
    {
        $pcntlStatus = 0;
        while (($pid = $this->getPcntlBridge()->pcntlWait($pcntlStatus, WNOHANG|WUNTRACED)) > 0) {
            $this->getWrapper()->raiseWorkerExitedEvent($pid, $pid, 1);
        }
    }

    public function onKernelLoop(SchedulerEvent $event)
    {
        // TODO: Implement onKernelLoop() method.
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