<?php

namespace Zeus\Kernel\ProcessManager;

abstract class AbstractThread extends AbstractProcess implements ThreadInterface
{
    protected $threadId = 'main';

    /**
     * @return mixed
     */
    public function getThreadId()
    {
        return $this->threadId;
    }

    /**
     * @param $id
     * @return $this
     */
    public function setThreadId($id)
    {
        $this->threadId = $id;

        return $this;
    }
}