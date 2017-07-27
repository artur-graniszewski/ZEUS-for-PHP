<?php

namespace Zeus\Kernel\ProcessManager;

use Zend\EventManager\EventManagerInterface;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;

interface WorkerInterface
{
    /**
     * @return IpcAdapterInterface
     */
    public function getSchedulerIpc();

    /**
     * @param IpcAdapterInterface $ipcAdapter
     * @return $this
     */
    public function setSchedulerIpc(IpcAdapterInterface $ipcAdapter);

    /**
     * @return EventManagerInterface
     */
    public function getEventManager();
}