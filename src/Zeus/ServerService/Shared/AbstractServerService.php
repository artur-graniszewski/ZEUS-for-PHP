<?php

namespace Zeus\ServerService\Shared;

use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler;
use Zeus\ServerService\ServerServiceInterface;

abstract class AbstractServerService implements ServerServiceInterface
{
    /** @var mixed[] */
    protected $config;

    /** @var Scheduler */
    protected $scheduler;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * AbstractService constructor.
     * @param mixed[] $config
     * @param Scheduler $scheduler
     * @param LoggerInterface $logger
     */
    public function __construct(array $config = [], Scheduler $scheduler, LoggerInterface $logger)
    {
        $this->scheduler = $scheduler;
        $this->config = $config;
        $this->logger = $logger;
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
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param mixed[] $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * @return Scheduler
     */
    public function getScheduler()
    {
        return $this->scheduler;
    }

    public function setScheduler(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}