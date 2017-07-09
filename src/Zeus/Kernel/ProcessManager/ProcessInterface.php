<?php

namespace Zeus\Kernel\ProcessManager;

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