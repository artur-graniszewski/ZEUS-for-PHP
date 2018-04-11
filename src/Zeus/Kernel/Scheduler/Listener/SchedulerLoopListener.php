<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\Discipline\DisciplineInterface;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerLifeCycleFacade;

class SchedulerLoopListener extends AbstractWorkerLifeCycleListener
{
    /** @var DisciplineInterface */
    private $discipline;

    public function __construct(WorkerLifeCycleFacade $workerLifeCycle, DisciplineInterface $discipline)
    {
        parent::__construct($workerLifeCycle);
        $this->discipline = $discipline;
    }

    public function __invoke(SchedulerEvent $event)
    {
        $scheduler = $event->getScheduler();
        if ($scheduler->isTerminating()) {
            return;
        }

        $discipline = $this->discipline;

        $toTerminate = $discipline->getWorkersToTerminate();
        $toCreate = $discipline->getAmountOfWorkersToCreate();
        $workers = $scheduler->getWorkers();
        foreach ($toTerminate as $worker) {
            $uid = $worker->getUid();
            $scheduler->getLogger()->debug(sprintf('Stopping worker %d', $uid));
            $this->workerLifeCycle->stop($worker, true);

            if (isset($workers[$uid])) {
                $workerState = $workers[$uid];
                $workerState->setTime(microtime(true));
                $workerState->setCode(WorkerState::TERMINATED);
            }
        }

        $this->startWorkers($toCreate);
    }
}