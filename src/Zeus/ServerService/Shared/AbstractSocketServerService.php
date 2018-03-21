<?php

namespace Zeus\ServerService\Shared;

use Zeus\ServerService\Shared\Networking\MessageComponentInterface;
use Zeus\ServerService\Shared\Networking\GatewayMessageBroker;
use Zeus\ServerService\Shared\Networking\NetworkServer;

class AbstractSocketServerService extends AbstractServerService
{
    /**
     * @param MessageComponentInterface $messageComponent
     * @param AbstractNetworkServiceConfig $config
     */
    protected function getServer(MessageComponentInterface $messageComponent, AbstractNetworkServiceConfig $config)
    {
        $scheduler = $this->getScheduler();
        $module = $scheduler->getMultiProcessingModule();
        $broker = new NetworkServer($config, $messageComponent, $this->getLogger(), $module::getCapabilities());
        $broker->attach($scheduler->getEventManager());
    }
}