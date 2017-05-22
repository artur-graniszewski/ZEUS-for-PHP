<?php

namespace Zeus\Kernel\ProcessManager;

use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;

interface ProcessInterface
{
    /**
     * @return int
     */
    public function getId();

    /**
     * @param string $processId
     * @return $this
     */
    public function setId($processId);

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