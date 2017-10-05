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
        $this->logger = $scheduler->getLogger();
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @return $this
     */
    public function start()
    {
        $this->getScheduler()->start(true);

        return $this;
    }

    /**
     * @return $this
     */
    public function stop()
    {
        $this->getScheduler()->stop();

        return $this;
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
     * @return $this
     */
    public function setConfig($config)
    {
        $this->config = $config;
        return $this;
    }

    /**
     * @return Scheduler
     */
    public function getScheduler()
    {
        return $this->scheduler;
    }

    /**
     * @param Scheduler $scheduler
     * @return $this
     */
    public function setScheduler($scheduler)
    {
        $this->scheduler = $scheduler;

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
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;

        return $this;
    }
}