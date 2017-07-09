<?php

namespace Zeus\Kernel\ProcessManager;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;

interface WorkerInterface
{
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
     * @return EventManagerInterface
     */
    public function getEventManager();
}