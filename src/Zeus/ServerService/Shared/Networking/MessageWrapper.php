<?php

namespace Zeus\ServerService\Shared\Networking;

use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\ServerService\Shared\Networking\Service\RegistratorService;

class MessageWrapper implements HeartBeatMessageInterface, MessageComponentInterface
{
    private $broker;

    private $message;

    public function __construct(SocketMessageBroker $broker, MessageComponentInterface $message)
    {
        $this->broker = $broker;
        $this->message = $message;
    }

    public function getMessageComponent()
    {
        return $this->message;
    }

    private function getBroker() : SocketMessageBroker
    {
        return $this->broker;
    }

    public function onHeartBeat(NetworkStreamInterface $connection, $data = null)
    {
        $messageComponent = $this->getMessageComponent();
        if ($messageComponent instanceof HeartBeatMessageInterface) {
            $messageComponent->onHeartBeat($connection, $data);
        }
    }

    public function onOpen(NetworkStreamInterface $connection)
    {
        $this->getMessageComponent()->onOpen($connection);
    }

    public function onMessage(NetworkStreamInterface $connection, string $message)
    {
        $this->getMessageComponent()->onMessage($connection, $message);
    }

    public function onClose(NetworkStreamInterface $connection)
    {
        $broker = $this->getBroker();
        $this->getMessageComponent()->onClose($connection);

        $this->getBroker()->getRegistrator()->notifyRegistrator(RegistratorService::STATUS_WORKER_READY, $broker->getWorkerUid(), $broker->getBackend()->getServer()->getLocalPort());
    }

    public function onError(NetworkStreamInterface $connection, \Throwable $exception)
    {
        $this->getMessageComponent()->onError($connection, $exception);
    }
}