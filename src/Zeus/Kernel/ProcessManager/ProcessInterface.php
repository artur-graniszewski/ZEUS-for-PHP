<?php

namespace Zeus\Kernel\ProcessManager;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;

interface ProcessInterface
{
    /**
     * @param string $processId
     * @return $this
     */
    public function setId($processId);

    /**
     * @return int
     */
    public function getId();

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger);

    /**
     * @return \Zend\Log\LoggerInterface
     */
    public function getLogger();

    /**
     * @param EventManagerInterface $eventManager
     * @return $this
     */
    public function attach(EventManagerInterface $eventManager);

    /**
     * @return ConfigInterface
     */
    public function getConfig();

    /**
     * @param ConfigInterface $config
     * @return $this
     */
    public function setConfig(ConfigInterface $config);

    /**
     * @return EventManagerInterface
     */
    public function getEventManager();

    /**
     * @return IpcAdapterInterface
     */
    public function getIpc();

    /**
     * @param $ipcAdapter
     * @return $this
     */
    public function setIpc($ipcAdapter);

}