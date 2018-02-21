<?php

namespace Zeus\ServerService;

use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler;

interface ServerServiceInterface
{
    /**
     * ServiceInterface constructor.
     * @param mixed[] $config
     * @param Scheduler $scheduler
     * @param LoggerInterface $logger
     */
    public function __construct(array $config, Scheduler $scheduler, LoggerInterface $logger);

    public function start();

    public function stop();

    public function getConfig() : array;

    public function getScheduler() : Scheduler;
}