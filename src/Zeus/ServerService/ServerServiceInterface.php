<?php

namespace Zeus\ServerService;

use Zend\Log\LoggerInterface;
use Zeus\Kernel\SchedulerInterface;

interface ServerServiceInterface
{
    /**
     * ServiceInterface constructor.
     * @param mixed[] $config
     * @param SchedulerInterface $scheduler
     * @param LoggerInterface $logger
     */
    public function __construct(array $config, SchedulerInterface $scheduler, LoggerInterface $logger);

    public function start();

    public function stop();

    public function getConfig() : array;

    public function getScheduler() : SchedulerInterface;
}