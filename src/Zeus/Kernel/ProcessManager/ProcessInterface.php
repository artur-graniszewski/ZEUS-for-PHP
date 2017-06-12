<?php

namespace Zeus\Kernel\ProcessManager;

use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;

interface ProcessInterface
{
    /**
     * @return int
     */
    public function getProcessId();

    /**
     * @param string $processId
     * @return $this
     */
    public function setProcessId($processId);

    /**
     * @return IpcAdapterInterface
     */
    public function getIpc();

    /**
     * @param $ipcAdapter
     * @return $this
     */
    public function setIpc($ipcAdapter);

    /**
     * @return $this
     */
    public function start();

}