<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;

class SchedulerStartListener extends AbstractWorkerLifeCycleListener
{
    public function __invoke(SchedulerEvent $event)
    {
        $scheduler = $event->getScheduler();
        $scheduler->getEventManager()->attach(WorkerEvent::EVENT_CREATE, function(WorkerEvent $e) { $this->registerNewWorker($e);}, WorkerEvent::PRIORITY_FINALIZE);
        $scheduler->getLogger()->notice("Scheduler started");

        $pid = $scheduler->getWorker()->getProcessId();

        $fileName = $this->getUidFile($scheduler->getConfig());
        if (!@file_put_contents($fileName, $pid)) {
            throw new SchedulerException(sprintf("Could not write to PID file: %s, aborting", $fileName), SchedulerException::LOCK_FILE_ERROR);
        }

        $this->startWorkers($scheduler->getConfig()->getStartProcesses());
    }

    private function startWorkers(int $amount)
    {
        if ($amount === 0) {
            return;
        }

        for ($i = 0; $i < $amount; ++$i) {
            $this->workerLifeCycle->start([]);
        }
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