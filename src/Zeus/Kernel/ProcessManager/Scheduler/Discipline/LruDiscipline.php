<?php

namespace Zeus\Kernel\ProcessManager\Scheduler\Discipline;

use Zeus\Kernel\ProcessManager\Config;
use Zeus\Kernel\ProcessManager\Scheduler\ProcessCollection;
use Zeus\Kernel\ProcessManager\Status\ProcessState;

class LruDiscipline implements DisciplineInterface
{
    /**
     * @param Config $config
     * @param ProcessCollection $processes
     * @return \mixed[]
     */
    public function manage(Config $config, ProcessCollection $processes)
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

        $r = $statusSummary[ProcessState::WAITING];;
        $t = count($processesToTerminate);
        $c = $processesToCreate;
        //trigger_error("R: $r, T: $t, C: $c");
        return [
            'create' => $processesToCreate,
            'terminate' => [],
            'soft_terminate' => $processesToTerminate,
        ];
    }

    /**
     * @param ProcessCollection $processes
     * @param Config $config
     * @param int[] $statusSummary
     * @return int|mixed
     */
    protected function getAmountOfProcessesToCreate(ProcessCollection $processes, Config $config, array $statusSummary)
    {
        $idleProcesses = $statusSummary[ProcessState::WAITING];
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
     * @param ProcessCollection $processes
     * @param Config $config
     * @param int[] $statusSummary
     * @return int[]
     */
    protected function getProcessesToTerminate(ProcessCollection $processes, Config $config, array $statusSummary)
    {
        $expireTime = microtime(true) - $config->getProcessIdleTimeout();

        $processesToTerminate = [];
        $idleProcesses = $statusSummary[ProcessState::WAITING];
        //$busyProcesses = $statusSummary[ProcessState::RUNNING];
        //$terminatedProcesses = $statusSummary[ProcessStatus::STATUS_EXITING] + $statusSummary[ProcessStatus::STATUS_KILL];

        // terminate idle processes, if number of them is too high.
        if ($idleProcesses > $config->getMaxSpareProcesses()) {
            $toTerminate = $idleProcesses - $config->getMaxSpareProcesses();
            $spareProcessesFound = 0;

            foreach ($processes as $pid => $processStatus) {
                if (!ProcessState::isIdle($processStatus)) {
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