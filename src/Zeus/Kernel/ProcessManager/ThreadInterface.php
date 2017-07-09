<?php

namespace Zeus\Kernel\ProcessManager;

interface ThreadInterface
{
    /**
     * @return int
     */
    public function getThreadId() : int;

    /**
     * @param mixed $id
     * @return $this
     */
    public function setThreadId(int $id);
}