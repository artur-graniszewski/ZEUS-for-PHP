<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerAwareTrait;
use Zend\Log\LoggerInterface;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;
use Zeus\ServerService\Shared\Networking\Service\BackendService;
use Zeus\ServerService\Shared\Networking\Service\FrontendService;
use Zeus\ServerService\Shared\Networking\Service\RegistratorService;

/**
 * Class SocketMessageBroker
 * @internal
 */
final class SocketMessageBroker
{
    use LoggerAwareTrait;

    /** @var MessageComponentInterface */
    private $message;

    /** @var FrontendService */
    private $frontendService;

    /** @var BackendService */
    private $backendService;

    /** @var RegistratorService */
    private $registratorService;

    public function __construct(AbstractNetworkServiceConfig $config, MessageComponentInterface $message, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->message = $message;

        $backend = new BackendService($this);
        $backend->setLogger($logger);
        $backend->setBackendServer($this->getSocketServer());
        $this->backendService = $backend;

        $registrator = new RegistratorService();
        $registrator->setLogger($logger);
        $registrator->setRegistratorServer($this->getSocketServer());
        $this->registratorService = $registrator;

        $frontend = new FrontendService($registrator, $config);
        $frontend->setLogger($logger);
        $frontend->setFrontendServer($this->getSocketServer());
        $this->frontendService = $frontend;
    }

    private function getSocketServer() : SocketServer
    {
        $server = new SocketServer();
        try {
            $server->setReuseAddress(true);
        } catch (UnsupportedOperationException $exception) {
            $this->getLogger()->warn("Reuse address feature for Socket Streams is unsupported");
        }
        $server->setSoTimeout(0);
        $server->setTcpNoDelay(true);

        return $server;
    }

    public function getFrontend() : FrontendService
    {
        return $this->frontendService;
    }

    public function getRegistrator() : RegistratorService
    {
        return $this->registratorService;
    }

    public function getBackend() : BackendService
    {
        return $this->backendService;
    }

    public function attach(EventManagerInterface $events)
    {
        $this->frontendService->attach($events);
        $this->backendService->attach($events);
        $this->registratorService->attach($events);
    }

    public function getConfig() : AbstractNetworkServiceConfig
    {
        return $this->config;
    }

    public function onHeartBeat(NetworkStreamInterface $connection)
    {
        if ($this->message instanceof HeartBeatMessageInterface) {
            $this->message->onHeartBeat($connection, []);
        }
    }

    public function onOpen(NetworkStreamInterface $connection)
    {
        $this->message->onOpen($connection);
    }

    public function onMessage(NetworkStreamInterface $connection, string $message)
    {
        $this->message->onMessage($connection, $message);
    }

    public function onClose(NetworkStreamInterface $connection)
    {
        $this->message->onClose($connection);
    }

    public function onError(NetworkStreamInterface $connection, \Throwable $exception)
    {
        $this->message->onError($connection, $exception);
    }
}