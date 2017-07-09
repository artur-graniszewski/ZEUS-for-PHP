<?php

namespace Zeus\Kernel\ProcessManager\Scheduler;

use Zeus\Kernel\ProcessManager\Shared\FixedCollection;
use Zeus\Kernel\ProcessManager\Status\ProcessState;

class ProcessCollection extends FixedCollection
{
    /**
     * @return int[]
     */
    public function getStatusSummary()
    {
        $statuses = [
            ProcessState::WAITING => 0,
            ProcessState::RUNNING => 0,
            ProcessState::EXITING => 0,
            ProcessState::TERMINATED => 0
        ];

        foreach ($this->values as $key => $taskStatus) {
            if (!$taskStatus) {
                continue;
            }

            $statuses[$taskStatus['code']]++;
        }

        return $statuses;
    }
}