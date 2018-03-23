<?php

namespace Zeus\Kernel\Scheduler\Discipline;

use Zeus\Kernel\Scheduler\ConfigInterface;
use Zeus\Kernel\Scheduler\Shared\WorkerCollection;
use Zeus\Kernel\Scheduler\Status\WorkerState;

use function min;
use function microtime;
use function ksort;

class LruDiscipline implements DisciplineInterface
{
    /** @var ConfigInterface **/
    private $config;

    /** @var WorkerCollection */
    private $workers;

    public function setConfig(ConfigInterface $config)
    {
        $this->config = $config;
    }

    public function setWorkersCollection(WorkerCollection $workers)
    {
        $this->workers = $workers;
    }

    /**
     * @return int
     */
    public function getAmountOfWorkersToCreate() : int
    {
        $config = $this->config;
        $workers = $this->workers;

        $statusSummary = $workers->getStatusSummary();

        $idleWorkers = $statusSummary[WorkerState::WAITING];
        $allWorkers = $workers->count();
        $toCreate = 0;

        // start additional workers, if number of them is too small.
        if ($idleWorkers < $config->getMinSpareProcesses()) {
            $idleWorkerSlots = $workers->getSize() - $workers->count();

            $toCreate = min($idleWorkerSlots, $config->getMinSpareProcesses() - $idleWorkers);
        }

        if ($allWorkers === 0 && $config->getMinSpareProcesses() === 0 && $config->getMaxSpareProcesses() > 0) {
            $toCreate = $config->getMaxSpareProcesses();
        }

        return $toCreate;
    }

    /**
     * @return WorkerState[]
     */
    public function getWorkersToTerminate() : array
    {
        $config = $this->config;
        $workers = $this->workers;

        $statusSummary = $workers->getStatusSummary();
        $expireTime = microtime(true) - $config->getProcessIdleTimeout();

        $workersToTerminate = [];
        $idleWorkers = $statusSummary[WorkerState::WAITING];

        // terminate idle workers, if number of them is too high.
        $toTerminate = $idleWorkers - $config->getMaxSpareProcesses();

        if ($toTerminate <= 0) {
            return [];
        }

        $spareWorkersFound = 0;

        foreach ($workers as $uid => $workerStatus) {
            if (!$workerStatus->isIdle()) {
                continue;
            }

            $workerTime = $workerStatus->getTime();
            if ($workerTime < $expireTime) {
                $workersToTerminate[$workerTime][] = $uid;
                ++$spareWorkersFound;

                if ($spareWorkersFound === $toTerminate) {
                    break;
                }
            }
        }

        ksort($workersToTerminate, SORT_ASC);

        $result = [];

        // unwind all workers...
        foreach ($workersToTerminate as $workers) {
            foreach ($workers as $uid) {
                $result[] = $this->workers[$uid];
            }
        }

        return $result;
    }
}