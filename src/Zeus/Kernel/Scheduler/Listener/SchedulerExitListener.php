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
            $this->logException($exception, $scheduler->getLogger());
        }

        $scheduler->setTerminating(true);
        $scheduler->getLogger()->notice("Scheduler shutting down");
        $scheduler->getLogger()->debug("Stopping all workers");
        $workers = $scheduler->getWorkers();
        if ($workers) {
            foreach ($workers as $worker) {
                $uid = $worker->getUid();
                $scheduler->getLogger()->debug(sprintf('Stopping worker %d', $uid));
                $this->workerLifeCycle->stop($worker, false);

                $worker->setTime(microtime(true));
                $worker->setCode(WorkerState::TERMINATED);
            }
        }

        @unlink($this->getUidFile($scheduler->getConfig()));
    }

    private function stopWorker(WorkerState $worker, bool $isSoftStop)
    {



    }

}