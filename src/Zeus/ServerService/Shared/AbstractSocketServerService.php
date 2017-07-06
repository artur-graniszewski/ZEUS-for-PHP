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
        $this->logger->info(sprintf('Launching server on %s%s', $config->getListenAddress(), $config->getListenPort() ? ':' . $config->getListenPort(): ''));

        $subscriber = new SocketMessageBroker($config, $messageComponent);
        $subscriber->attach($this->scheduler->getEventManager());

        return $subscriber;
    }
}