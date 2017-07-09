<?php

namespace Zeus\Kernel\ProcessManager\Scheduler\Discipline;

use Zeus\Kernel\ProcessManager\ConfigInterface;
use Zeus\Kernel\ProcessManager\Scheduler\WorkerCollection;
use Zeus\Kernel\ProcessManager\Status\WorkerState;

class LruDiscipline implements DisciplineInterface
{
    /**
     * @param ConfigInterface $config
     * @param WorkerCollection $processes
     * @return \mixed[]
     */
    public function manage(ConfigInterface $config, WorkerCollection $processes) : array
    {
        $result = [
            'create' => 0,
            'terminate' => [],
            'soft_terminate' => [],
        ];

        if (!$config->isProcessCacheEnabled()) {
            return $result;
        }

        $statusSummary = $processes->getStatusSummary();
        $processesToTerminate = $this->getProcessesToTerminate($processes, $config, $statusSummary);
        $processesToCreate = $this->getAmountOfProcessesToCreate($processes, $config, $statusSummary);

//        $r = $statusSummary[WorkerState::WAITING];;
//        $t = count($processesToTerminate);
//        $c = $processesToCreate;
//        //trigger_error("R: $r, T: $t, C: $c");
        return [
            'create' => $processesToCreate,
            'terminate' => [],
            'soft_terminate' => $processesToTerminate,
        ];
    }

    /**
     * @param WorkerCollection $processes
     * @param ConfigInterface $config
     * @param int[] $statusSummary
     * @return int
     */
    protected function getAmountOfProcessesToCreate(WorkerCollection $processes, ConfigInterface $config, array $statusSummary) : int
    {
        $idleProcesses = $statusSummary[WorkerState::WAITING];
        $allProcesses = $processes->count();

        // start additional processes, if number of them is too small.
        if ($idleProcesses < $config->getMinSpareProcesses()) {
            $idleProcessSlots = $processes->getSize() - $processes->count();

            return min($idleProcessSlots, $config->getMinSpareProcesses() - $idleProcesses);
        }

        if ($allProcesses === 0 && $config->getMinSpareProcesses() === 0 && $config->getMaxSpareProcesses() > 0) {

            return $config->getMaxSpareProcesses();
        }

        return 0;
    }

    /**
     * @param WorkerCollection $processes
     * @param ConfigInterface $config
     * @param int[] $statusSummary
     * @return int[]
     */
    protected function getProcessesToTerminate(WorkerCollection $processes, ConfigInterface $config, array $statusSummary) : array
    {
        $expireTime = microtime(true) - $config->getProcessIdleTimeout();

        $processesToTerminate = [];
        $idleProcesses = $statusSummary[WorkerState::WAITING];
        //$busyProcesses = $statusSummary[ProcessState::RUNNING];
        //$terminatedProcesses = $statusSummary[ProcessStatus::STATUS_EXITING] + $statusSummary[ProcessStatus::STATUS_KILL];

        // terminate idle processes, if number of them is too high.
        if ($idleProcesses > $config->getMaxSpareProcesses()) {
            $toTerminate = $idleProcesses - $config->getMaxSpareProcesses();
            $spareProcessesFound = 0;

            foreach ($processes as $pid => $processStatus) {
                if (!WorkerState::isIdle($processStatus)) {
                    continue;
                }

                if ($processStatus['time'] < $expireTime) {
                    $processesToTerminate[$processStatus['time']] = $pid;
                    ++$spareProcessesFound;

                    if ($spareProcessesFound === $toTerminate) {
                        break;
                    }
                }
            }

            ksort($processesToTerminate, SORT_ASC);
            $processesToTerminate = array_slice($processesToTerminate, 0, $toTerminate);
        }

        return $processesToTerminate;
    }
}