<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
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
    /** @var MessageComponentInterface */
    private $message;

    /** @var LoggerInterface */
    private $logger;

    /** @var FrontendService */
    private $frontendService;

    /** @var BackendService */
    private $backendService;

    /** @var RegistratorService */
    private $registratorService;

    public function __construct(AbstractNetworkServiceConfig $config, MessageComponentInterface $message)
    {
        $this->config = $config;
        $this->message = $message;
        $this->frontendService = new FrontendService($this);
        $this->backendService = new BackendService($this);
        $this->registratorService = new RegistratorService($this);
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

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getLogger() : LoggerInterface
    {
        if (!isset($this->logger)) {
            throw new \LogicException("Logger not available");
        }
        return $this->logger;
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