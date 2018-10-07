<?php

namespace Zeus\Kernel\Scheduler\Listener;

use Zeus\Kernel\Scheduler\Discipline\DisciplineInterface;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerLifeCycleFacade;
use Zeus\Kernel\Scheduler\Event\SchedulerLoopRepeated;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler\Shared\WorkerCollection;

class SchedulerLoopListener extends AbstractWorkerLifeCycleListener
{
    /** @var DisciplineInterface */
    private $discipline;

    /** @var WorkerCollection **/
    private $workers;
    
    public function __construct(LoggerInterface $logger, WorkerCollection $workers, WorkerLifeCycleFacade $workerLifeCycle, DisciplineInterface $discipline)
    {
        parent::__construct($logger, $workerLifeCycle);
        $this->workers = $workers;
        $this->discipline = $discipline;
    }

    public function __invoke(SchedulerLoopRepeated $event)
    {
        $discipline = $this->discipline;

        $toTerminate = $discipline->getWorkersToTerminate();
        $toCreate = $discipline->getAmountOfWorkersToCreate();
        $workers = $this->workers;
        foreach ($toTerminate as $worker) {
            $this->workerLifeCycle->stop($worker, true);
            $worker->setTime(microtime(true));
            $worker->setCode(WorkerState::TERMINATED);
        }

        $this->startWorkers($toCreate);
    }
}