<?php

namespace Zeus\ServerService\Shared\Networking;

use Throwable;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\SchedulerInterface;
use Zeus\ServerService\Shared\Networking\Service\RegistratorService;

class MessageObserver implements HeartBeatMessageInterface, MessageComponentInterface
{
    /** @var BrokerStrategy */
    private $broker;

    /** @var MessageComponentInterface */
    private $message;

    /** @var WorkerState */
    private $worker;

    /** @var SchedulerInterface */
    private $scheduler;

    public function __construct(BrokerStrategy $broker, MessageComponentInterface $message)
    {
        $this->broker = $broker;
        $this->message = $message;
    }

    public function setWorker(WorkerState $worker)
    {
        $this->worker = $worker;
    }

    public function setScheduler(SchedulerInterface $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function getScheduler() : SchedulerInterface
    {
        return $this->scheduler;
    }

    public function onOpen(NetworkStreamInterface $connection)
    {
        $this->setConnectionStatus(RegistratorService::STATUS_WORKER_BUSY);
        $function = function() use ($connection) {
            $worker = $this->getWorker();
            $worker->setRunning();
            $this->getScheduler()->syncWorker($worker);
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
            $worker = $this->getWorker();
            $worker->setWaiting();
            $this->getScheduler()->syncWorker($worker);
            $this->getMessageComponent()->onClose($connection);
        };

        $this->safeExecute($function, $connection, RegistratorService::STATUS_WORKER_READY);
    }

    public function onError(NetworkStreamInterface $connection, Throwable $exception)
    {
        $function = function() use ($connection, $exception) {
            $worker = $this->getWorker();
            $worker->setWaiting();
            $this->getScheduler()->syncWorker($worker);
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
                $worker = $this->getWorker();
                // don't send READY status just before stopping the worker, otherwise we risk race-conditions in registrator
                if (!($worker->isLastTask() && $status === RegistratorService::STATUS_WORKER_READY)) {
                    $this->setConnectionStatus($status);
                }
                $worker->setWaiting();
                $this->getScheduler()->syncWorker($worker);
            }
        }
    }

    private function setConnectionStatus(string $status)
    {
        $broker = $this->getBroker();
        $broker->setWorkerStatus($status);
    }

    private function getMessageComponent() : MessageComponentInterface
    {
        return $this->message;
    }

    private function getBroker() : BrokerStrategy
    {
        return $this->broker;
    }

    public function getWorker() : WorkerState
    {
        return $this->worker;
    }
}