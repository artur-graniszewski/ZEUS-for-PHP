<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\ServerService\Shared\Logger\ExceptionLoggerTrait;

class SchedulerExitListener extends AbstractWorkerLifeCycleListener
{
    use ExceptionLoggerTrait;

    public function __invoke(SchedulerEvent $event)
    {
        $scheduler = $event->getScheduler();
        $exception = $event->getParam('exception', null);

        if ($exception) {
            $this->logException($exception, $this->logger);
        }

        $scheduler->setTerminating(true);
        $this->logger->notice("Scheduler shutting down");
        $this->logger->debug("Stopping all workers");
        $workers = $scheduler->getWorkers();
        if ($workers) {
            foreach ($workers as $worker) {
                $uid = $worker->getUid();
                $this->logger->debug(sprintf('Stopping worker %d', $uid));
                $this->workerLifeCycle->stop($worker, false);

                $worker->setTime(microtime(true));
                $worker->setCode(WorkerState::TERMINATED);
            }
        }

        @unlink($this->getUidFile($scheduler->getConfig()));
    }
}