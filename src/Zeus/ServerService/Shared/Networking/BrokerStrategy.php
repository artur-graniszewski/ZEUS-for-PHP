<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;

interface BrokerStrategy
{
    public function __construct(LoggerInterface $logger, AbstractNetworkServiceConfig $config, MessageComponentInterface $message);

    public function attach(EventManagerInterface $events);

    public function setWorkerStatus(string $status) : bool;
}