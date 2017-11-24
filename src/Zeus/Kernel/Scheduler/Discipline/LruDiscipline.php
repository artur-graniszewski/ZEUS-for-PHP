<?php

namespace Zeus\Kernel\Scheduler\Discipline;

use Zeus\Kernel\Scheduler\ConfigInterface;
use Zeus\Kernel\Scheduler\Shared\WorkerCollection;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use function min;
use function microtime;

class LruDiscipline implements DisciplineInterface
{
    /**
     * @param ConfigInterface $config
     * @param WorkerCollection $workers
     * @return \mixed[]
     */
    public function manage(ConfigInterface $config, WorkerCollection $workers) : array
    {
        $result = [
            'create' => 0,
            'terminate' => [],
            'softTerminate' => [],
        ];

        if (!$config->isProcessCacheEnabled()) {
            return $result;
        }

        $statusSummary = $workers->getStatusSummary();
        $workersToTerminate = $this->getWorkersToTerminate($workers, $config, $statusSummary);
        $workersToCreate = $this->getAmountOfWorkersToCreate($workers, $config, $statusSummary);

        return [
            'create' => $workersToCreate,
            'terminate' => [],
            'softTerminate' => $workersToTerminate,
        ];
    }

    /**
     * @param WorkerCollection $workers
     * @param ConfigInterface $config
     * @param int[] $statusSummary
     * @return int
     */
    protected function getAmountOfWorkersToCreate(WorkerCollection $workers, ConfigInterface $config, array $statusSummary) : int
    {
        $idleWorkers = $statusSummary[WorkerState::WAITING];
        $allWorkers = $workers->count();

        // start additional processes, if number of them is too small.
        if ($idleWorkers < $config->getMinSpareProcesses()) {
            $idleWorkerSlots = $workers->getSize() - $workers->count();

            return min($idleWorkerSlots, $config->getMinSpareProcesses() - $idleWorkers);
        }

        if ($allWorkers === 0 && $config->getMinSpareProcesses() === 0 && $config->getMaxSpareProcesses() > 0) {

            return $config->getMaxSpareProcesses();
        }

        return 0;
    }

    /**
     * @param WorkerCollection $workers
     * @param ConfigInterface $config
     * @param int[] $statusSummary
     * @return int[]
     */
    protected function getWorkersToTerminate(WorkerCollection $workers, ConfigInterface $config, array $statusSummary) : array
    {
        $expireTime = microtime(true) - $config->getProcessIdleTimeout();

        $workersToTerminate = [];
        $idleWorkers = $statusSummary[WorkerState::WAITING];

        // terminate idle processes, if number of them is too high.
        $toTerminate = $idleWorkers - $config->getMaxSpareProcesses();

        if (!$toTerminate) {
            return [];
        }

        $spareWorkersFound = 0;

        foreach ($workers as $uid => $workerStatus) {
            if (!WorkerState::isIdle($workerStatus)) {
                continue;
            }

            if ($workerStatus['time'] < $expireTime) {
                $workersToTerminate[$workerStatus['time']][] = $uid;
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
            foreach ($workers as $worker) {
                $result[] = $worker;
            }
        }

        return $result;
    }
}