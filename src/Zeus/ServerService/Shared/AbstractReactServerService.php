<?php

namespace Zeus\ServerService\Shared;

use React\EventLoop\StreamSelectLoop;
use Zeus\ServerService\Shared\React\MessageComponentInterface;
use Zeus\ServerService\Shared\React\ReactEventSubscriber;
use Zeus\ServerService\Shared\React\ReactIoServer;
use Zeus\ServerService\Shared\React\ReactServer;

class AbstractReactServerService extends AbstractServerService
{
    /**
     * @param MessageComponentInterface $messageComponent
     * @param AbstractNetworkServiceConfig $config
     * @return ReactEventSubscriber
     */
    protected function getServer(MessageComponentInterface $messageComponent, AbstractNetworkServiceConfig $config)
    {
        $this->logger->info(sprintf('Launching server on %s%s', $config->getListenAddress(), $config->getListenPort() ? ':' . $config->getListenPort(): ''));
        $loop = new StreamSelectLoop();
        $reactServer = new ReactServer($loop);
        $reactServer->listen($config->getListenPort(), $config->getListenAddress());
        $loop->removeStream($reactServer->master);

        $server = new ReactIoServer($messageComponent, $reactServer, $loop);
        $reactSubscriber = new ReactEventSubscriber($loop, $server, 0.00001);
        $reactSubscriber->attach($this->scheduler->getEventManager());

        return $reactSubscriber;
    }
}