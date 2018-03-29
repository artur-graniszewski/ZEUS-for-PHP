<?php

namespace Zeus\ServerService\Shared;

use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler\Helper\PluginRegistry;
use Zeus\Kernel\SchedulerInterface;
use Zeus\ServerService\ServerServiceInterface;

abstract class AbstractServerService implements ServerServiceInterface
{
    use PluginRegistry;

    /** @var mixed[] */
    private $config;

    /** @var SchedulerInterface */
    private $scheduler;

    /** @var LoggerInterface */
    private $logger;

    /**
     * AbstractService constructor.
     * @param mixed[] $config
     * @param SchedulerInterface $scheduler
     * @param LoggerInterface $logger
     */
    public function __construct(array $config = [], SchedulerInterface $scheduler, LoggerInterface $logger)
    {
        $this->setScheduler($scheduler);
        $this->setConfig($config);
        $this->setLogger($logger);
    }

    public function start()
    {
        $this->getScheduler()->start(true);
    }

    public function stop()
    {
        $this->getScheduler()->stop();
    }

    /**
     * @return mixed[]
     */
    public function getConfig() : array
    {
        return $this->config;
    }

    /**
     * @param mixed[] $config
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    public function getScheduler() : SchedulerInterface
    {
        return $this->scheduler;
    }

    public function setScheduler(SchedulerInterface $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function getLogger() : LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}