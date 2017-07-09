<?php

namespace Zeus\Kernel\ProcessManager\Scheduler\Discipline;

use Zeus\Kernel\ProcessManager\ConfigInterface;
use Zeus\Kernel\ProcessManager\Scheduler\WorkerCollection;

interface DisciplineInterface
{
    /**
     * @param ConfigInterface $config
     * @param WorkerCollection $processes
     * @return \mixed[]
     */
    public function manage(ConfigInterface $config, WorkerCollection $processes);
}