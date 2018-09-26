<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\Command\CreateWorker;

class SchedulerStartListener extends AbstractWorkerLifeCycleListener
{
    public function __invoke(SchedulerEvent $event)
    {
        $scheduler = $event->getScheduler();
        $scheduler->getEventManager()->attach(CreateWorker::class, function(WorkerEvent $e) { $this->registerNewWorker($e);}, WorkerEvent::PRIORITY_FINALIZE);
        $scheduler->getLogger()->notice("Scheduler started with uid: " . $scheduler->getWorker()->getUid());

        $pid = $scheduler->getWorker()->getProcessId();

        $fileName = $this->getUidFile($scheduler->getConfig());
        if (!@file_put_contents($fileName, $pid)) {
            throw new SchedulerException(sprintf("Could not write to PID file: %s, aborting", $fileName), SchedulerException::LOCK_FILE_ERROR);
        }

        $this->startWorkers($scheduler->getConfig()->getStartProcesses());
    }

    private function registerNewWorker(WorkerEvent $event)
    {
        $status = $event->getWorker();
        $uid = $status->getUid();
        $status->setCode(WorkerState::WAITING);

        $workers = $event->getScheduler()->getWorkers();
        $workers[$uid] = $status;
    }
}