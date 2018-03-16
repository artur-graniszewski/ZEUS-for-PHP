<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;

interface BrokerStrategy
{
    public function __construct(AbstractNetworkServiceConfig $config, MessageComponentInterface $message, LoggerInterface $logger);

    public function attach(EventManagerInterface $events);

    public function setWorkerStatus(string $status) : bool;
}