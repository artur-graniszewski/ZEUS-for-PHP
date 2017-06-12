<?php

namespace Zeus\Kernel\ProcessManager;

interface ThreadInterface
{
    /**
     * @return mixed
     */
    public function getThreadId();

    /**
     * @param mixed $id
     * @return $this
     */
    public function setThreadId($id);
}