<?php

namespace Zeus\ServerService\Shared;

use Zeus\ServerService\Shared\Networking\SocketServer;
use Zeus\ServerService\Shared\Networking\MessageComponentInterface;
use Zeus\ServerService\Shared\Networking\SocketEventSubscriber;

class AbstractSocketServerService extends AbstractServerService
{
    /**
     * @param MessageComponentInterface $messageComponent
     * @param AbstractNetworkServiceConfig $config
     * @return SocketEventSubscriber
     */
    protected function getServer(MessageComponentInterface $messageComponent, AbstractNetworkServiceConfig $config)
    {
        $this->logger->info(sprintf('Launching server on %s%s', $config->getListenAddress(), $config->getListenPort() ? ':' . $config->getListenPort(): ''));
        $server = new SocketServer($config);

        $subscriber = new SocketEventSubscriber($server, $messageComponent);
        $subscriber->attach($this->scheduler->getEventManager());

        return $subscriber;
    }
}