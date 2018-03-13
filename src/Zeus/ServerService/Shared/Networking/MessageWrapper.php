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

    public function onOpen(NetworkStreamInterface $connection)
    {
        $this->setConnectionStatus(RegistratorService::STATUS_WORKER_BUSY);
        $function = function() use ($connection) {
            $this->getMessageComponent()->onOpen($connection);
        };

        $this->safeExecute($function, $connection, RegistratorService::STATUS_WORKER_READY);
    }

    public function onHeartBeat(NetworkStreamInterface $connection, $data = null)
    {
        /** @var HeartBeatMessageInterface $messageComponent */
        $messageComponent = $this->getMessageComponent();

        if (!$messageComponent instanceof HeartBeatMessageInterface) {
            return;
        }

        $function = function() use ($connection, $messageComponent, $data) {
            $messageComponent->onHeartBeat($connection, $data);
        };

        $this->safeExecute($function, $connection, RegistratorService::STATUS_WORKER_READY);
    }

    public function onMessage(NetworkStreamInterface $connection, string $message)
    {
        $function = function() use ($connection, $message) {
            $this->getMessageComponent()->onMessage($connection, $message);
        };

        $this->safeExecute($function, $connection, RegistratorService::STATUS_WORKER_READY);
    }

    public function onClose(NetworkStreamInterface $connection)
    {
        $function = function() use ($connection) {
            $this->getMessageComponent()->onClose($connection);
        };

        $this->safeExecute($function, $connection, RegistratorService::STATUS_WORKER_READY);
    }

    public function onError(NetworkStreamInterface $connection, \Throwable $exception)
    {
        $function = function() use ($connection, $exception) {
            $this->getMessageComponent()->onError($connection, $exception);
        };

        $this->safeExecute($function, $connection, RegistratorService::STATUS_WORKER_READY);
    }

    private function safeExecute(callable $function, NetworkStreamInterface $connection, string $status)
    {
        $messageComponent = $this->getMessageComponent();
        $wasClosed = $connection->isClosed();
        if ($messageComponent instanceof HeartBeatMessageInterface) {
            $function();
            if (!$wasClosed && $connection->isClosed()) {
                $this->setConnectionStatus($status);
            }
        }
    }

    private function setConnectionStatus(string $status)
    {
        $broker = $this->getBroker();
        $broker->getRegistrator()->notifyRegistrator($status, $broker->getWorkerIPC());
    }
}