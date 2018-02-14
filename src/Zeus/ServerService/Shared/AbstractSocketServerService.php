<?php

namespace Zeus\ServerService\Shared;

use Zeus\ServerService\Shared\Networking\MessageComponentInterface;
use Zeus\ServerService\Shared\Networking\SocketMessageBroker;

class AbstractSocketServerService extends AbstractServerService
{
    /**
     * @param MessageComponentInterface $messageComponent
     * @param AbstractNetworkServiceConfig $config
     * @return SocketMessageBroker
     */
    protected function getServer(MessageComponentInterface $messageComponent, AbstractNetworkServiceConfig $config)
    {
        $broker = new SocketMessageBroker($config, $messageComponent, $this->getLogger());
        $broker->attach($this->getScheduler()->getEventManager());

        return $broker;
    }
}