<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerAwareTrait;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler\Command\StartScheduler;
use Zeus\Kernel\Scheduler\MultiProcessingModule\MultiProcessingModuleCapabilities;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;

use function explode;
use function get_class;
use function array_pop;

class NetworkServer
{
    use LoggerAwareTrait;

    /** @var BrokerStrategy */
    private $strategy;

    /** @var AbstractNetworkServiceConfig */
    private $config;

    public function __construct(AbstractNetworkServiceConfig $config, MessageComponentInterface $message, LoggerInterface $logger, MultiProcessingModuleCapabilities $mpmCapabilities)
    {
        $this->config = $config;
        $this->setLogger($logger);

        if ($mpmCapabilities->isCopyingParentMemoryPages()) {
            $this->strategy = new DirectMessageBroker($logger, $config, $message);
        } else {
            $this->strategy = new GatewayMessageBroker($logger, $config, $message);
        }
    }

    public function getConfig() : AbstractNetworkServiceConfig
    {
        return $this->config;
    }

    public function attach(EventManagerInterface $eventManager)
    {
        $eventManager->attach(StartScheduler::class, function () {
            $config = $this->getConfig();
            $port = $config->getListenPort();
            $backendHost = 'tcp://' . $config->getListenAddress() . ($port ? ":$port" : '');
            $logger = $this->getLogger();
            $classPath = explode("\\", get_class($this->strategy));
            $strategyName = array_pop($classPath);
            $logger->notice("Starting Network Server");
            $logger->info("* Using $strategyName strategy");
            $logger->info('* Server listening on ' . $backendHost);
        }, SchedulerEvent::PRIORITY_INITIALIZE - 1);

        $this->strategy->attach($eventManager);
    }
}