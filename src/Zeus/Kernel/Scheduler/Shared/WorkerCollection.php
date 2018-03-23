<?php

namespace Zeus\Kernel\Scheduler\Shared;

use Zeus\Kernel\Scheduler\Status\WorkerState;

class WorkerCollection extends FixedCollection
{
    /**
     * @return int[]
     */
    public function getStatusSummary() : array
    {
        $statuses = [
            WorkerState::WAITING => 0,
            WorkerState::RUNNING => 0,
            WorkerState::EXITING => 0,
            WorkerState::TERMINATED => 0
        ];

        foreach ($this->values as $key => $workerStatus) {
            if (!$workerStatus) {
                continue;
            }

            $statuses[$workerStatus->getCode()]++;
        }

        return $statuses;
    }
}