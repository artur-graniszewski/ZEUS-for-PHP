<?php

namespace Zeus\ServerService\Shared;

use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler;
use Zeus\ServerService\ServerServiceInterface;

abstract class AbstractServerService implements ServerServiceInterface
{
    use Scheduler\Helper\PluginRegistry;

    /** @var mixed[] */
    private $config;

    /** @var Scheduler */
    private $scheduler;

    /** @var LoggerInterface */
    private $logger;

    /**
     * AbstractService constructor.
     * @param mixed[] $config
     * @param Scheduler $scheduler
     * @param LoggerInterface $logger
     */
    public function __construct(array $config = [], Scheduler $scheduler, LoggerInterface $logger)
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

    public function getScheduler() : Scheduler
    {
        return $this->scheduler;
    }

    public function setScheduler(Scheduler $scheduler)
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