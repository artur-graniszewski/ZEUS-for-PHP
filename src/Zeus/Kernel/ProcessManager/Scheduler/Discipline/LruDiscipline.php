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

        $now = microtime(true);
        $processesToTerminate = [];
        $processesToCreate = 0;

        /**
         * Time after which idle process should be ultimately terminated.
         *
         * @var float
         */
        $expireTime = $now - $config->getProcessIdleTimeout();

        $statusSummary = $processes->getStatusSummary();
        $idleProcesses = $statusSummary[ProcessState::WAITING];
        //$busyProcesses = $statusSummary[ProcessState::RUNNING];
        //$terminatedProcesses = $statusSummary[ProcessStatus::STATUS_EXITING] + $statusSummary[ProcessStatus::STATUS_KILL];
        $allProcesses = $processes->count();

        // terminate idle processes, if number of them is too high.
        if ($idleProcesses > $config->getMaxSpareProcesses()) {
            $toTerminate = $idleProcesses - $config->getMaxSpareProcesses();
            $candidatesToTermination = 0;

            foreach ($processes as $pid => $processStatus) {
                if (!ProcessState::isIdle($processStatus)) {
                    continue;
                }

                if ($processStatus['time'] < $expireTime) {
                    $processesToTerminate[$processStatus['time']] = $pid;
                    ++$candidatesToTermination;

                    if ($candidatesToTermination === $toTerminate || $candidatesToTermination === $config->getMaxSpareProcesses()) {
                        break;
                    }
                }
            }
            
            ksort($processesToTerminate, SORT_ASC);
            $processesToTerminate = array_slice($processesToTerminate, 0, $toTerminate);
        }

        // start additional processes, if number of them is too small.
        if ($idleProcesses < $config->getMinSpareProcesses()) {
            $idleProcessSlots = $processes->getSize() - $processes->count();

            $processesToCreate = min($idleProcessSlots, $config->getMinSpareProcesses());
        }

        if ($allProcesses === 0 && $config->getMinSpareProcesses() === 0 && $config->getMaxSpareProcesses() > 0) {
            $processesToCreate = $config->getMaxSpareProcesses();
        }

        return [
            'create' => $processesToCreate,
            'terminate' => [],
            'soft_terminate' => $processesToTerminate,
        ];
    }
}