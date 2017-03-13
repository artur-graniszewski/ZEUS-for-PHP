<?php

namespace Zeus\Kernel\ProcessManager\Scheduler\Discipline;


use Zeus\Kernel\ProcessManager\Config;
use Zeus\Kernel\ProcessManager\Scheduler\ProcessCollection;

interface DisciplineInterface
{
    /**
     * @param Config $config
     * @param ProcessCollection $processes
     * @return \mixed[]
     */
    public function manage(Config $config, ProcessCollection $processes);
}