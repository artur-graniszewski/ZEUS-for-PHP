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

    public function getConfig() : ConfigInterface
    {
        return $this->config;
    }

    public function setConfig(ConfigInterface $config)
    {
        $this->config = $config;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getLogger() : LoggerInterface
    {
        return $this->logger;
    }

    public function setIpc(IpcServer $ipcAdapter)
    {
        $this->ipcAdapter = $ipcAdapter;
    }

    public function getIpc() : IpcServer
    {
        return $this->ipcAdapter;
    }

    public function setIsTerminating(bool $isTerminating)
    {
        $this->isTerminating = $isTerminating;
    }

    public function isTerminating() : bool
    {
        return $this->isTerminating;
    }
}