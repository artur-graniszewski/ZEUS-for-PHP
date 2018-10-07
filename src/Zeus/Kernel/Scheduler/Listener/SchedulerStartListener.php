<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\Scheduler\Command\CreateWorker;
use Zeus\Kernel\SchedulerInterface;

class SchedulerStartListener extends AbstractWorkerLifeCycleListener
{
    /** @var SchedulerInterface */
    private $scheduler;

    public function __invoke(SchedulerEvent $event)
    {
        $scheduler = $event->getScheduler();
        $scheduler->getEventManager()->attach(CreateWorker::class, function(CreateWorker $e) { $this->registerNewWorker($e);}, WorkerEvent::PRIORITY_FINALIZE);
        $this->scheduler = $scheduler;

        $pid = $scheduler->getWorker()->getUid();

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

        $workers = $this->scheduler->getWorkers();
        $workers[$uid] = $status;
    }
}