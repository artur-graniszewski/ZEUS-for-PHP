<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\Exception\SchedulerException;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;

class SchedulerTerminateListener extends AbstractWorkerLifeCycleListener
{
    public function __invoke(SchedulerEvent $event)
    {
        $scheduler = $event->getScheduler();
        $scheduler->getLogger()->debug("Stopping scheduler");
        $fileName = $this->getUidFile($event->getScheduler()->getConfig());

        $uid = @file_get_contents($fileName);
        if (!$uid) {
            throw new SchedulerException("Scheduler not running", SchedulerException::SCHEDULER_NOT_RUNNING);
        }

        $uid = intval($uid);

        $scheduler->getLogger()->info("Terminating scheduler process #$uid");
        /** @var WorkerState $worker */
        $worker = $event->getTarget();
        $worker->setUid($uid);
        $worker->setProcessId($uid);

        $globalCount = 0;
        foreach ([true, false] as $isSoftStop) {
            $this->workerLifeCycle->stop($worker, $isSoftStop);

            $count = 0;
            while (($uid = @file_get_contents($fileName)) && $count < 5) {
                if ($globalCount > 3 && $globalCount % 2) {
                    $scheduler->getLogger()->debug("Waiting for scheduler to shutdown");
                }
                sleep(1);
                $count++;
                $globalCount++;
            }

            if (!$uid) {
                return;
            }
        }

        throw new SchedulerException("Scheduler not stopped", SchedulerException::LOCK_FILE_ERROR);
    }
}