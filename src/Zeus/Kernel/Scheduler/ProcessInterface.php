<?php

namespace Zeus\Kernel\Scheduler;

interface ProcessInterface
{
    /**
     * @return int
     */
    public function getProcessId() : int;

    /**
     * @param int $processId
     * @return $this
     */
    public function setProcessId(int $processId);

    /**
     * @return $this
     */
    public function start();

}