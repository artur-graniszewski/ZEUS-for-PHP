<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zend\Log\Logger;
use Zeus\Kernel\Scheduler\WorkerEvent;

class WorkerExitListener
{
    public function __invoke(WorkerEvent $event)
    {
        $uid = $event->getWorker()->getUid();

        $scheduler = $event->getScheduler();
        $scheduler->getLogger()->debug("Worker $uid exited");
        $workers = $scheduler->getWorkers();

        if (isset($workers[$uid])) {
            $workerState = $workers[$uid];

            if (!$workerState->isExiting() && $workerState->getTime() < microtime(true) - $scheduler->getConfig()->getProcessIdleTimeout()) {
                $scheduler->getLogger()->err("Worker $uid exited prematurely");
            }

            unset($workers[$uid]);
        }
    }
}