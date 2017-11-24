<?php

namespace Zeus\Kernel\Scheduler;

use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerAwareTrait;
use Zend\EventManager\EventsCapableInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\IpcServer;

abstract class AbstractService implements EventsCapableInterface, EventManagerAwareInterface
{
    use EventManagerAwareTrait;

    private $ipcAdapter;

    private $logger;

    /** @var ConfigInterface */
    private $config;

    /** @var bool */
    private $isTerminating = false;

    /**
     * @return ConfigInterface
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param ConfigInterface $config
     * @return $this
     */
    public function setConfig(ConfigInterface $config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param IpcServer $ipcAdapter
     * @return $this
     */
    public function setIpc(IpcServer $ipcAdapter)
    {
        $this->ipcAdapter = $ipcAdapter;

        return $this;
    }

    /**
     * @return IpcServer mixed
     */
    public function getIpc() : IpcServer
    {
        return $this->ipcAdapter;
    }

    /**
     * @param bool $isTerminating
     * @return $this
     */
    public function setIsTerminating(bool $isTerminating)
    {
        $this->isTerminating = $isTerminating;

        return $this;
    }

    /**
     * @return bool
     */
    public function isTerminating() : bool
    {
        return $this->isTerminating;
    }
}