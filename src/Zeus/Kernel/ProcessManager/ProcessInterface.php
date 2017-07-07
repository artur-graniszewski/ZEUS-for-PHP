<?php

namespace Zeus\Kernel\ProcessManager;

use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;

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
     * @return int
     */
    public function getThreadId() : int;

    /**
     * @param int $threadId
     * @return $this
     */
    public function setThreadId(int $threadId);

    /**
     * @return IpcAdapterInterface
     */
    public function getIpc();

    /**
     * @param IpcAdapterInterface $ipcAdapter
     * @return $this
     */
    public function setIpc(IpcAdapterInterface $ipcAdapter);

    /**
     * @return $this
     */
    public function start();

}