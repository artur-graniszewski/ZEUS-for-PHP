<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerAwareTrait;
use Zend\Log\LoggerInterface;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\SocketServer;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;
use Zeus\ServerService\Shared\Networking\Service\BackendService;

class DirectMessageBroker implements BrokerStrategy
{
    use LoggerAwareTrait;

    /** @var MessageObserver */
    private $message;

    /** @var BackendService */
    private $backend;

    /** @var string */
    private $backendHost = '';

    private $backendPort = 0;

    public function __construct(AbstractNetworkServiceConfig $config, MessageComponentInterface $message, LoggerInterface $logger)
    {
        $this->setLogger($logger);

        $port = $config->getListenPort();
        $this->backendHost = 'tcp://' . $config->getListenAddress();
        $this->backendPort = $port;

        $this->config = $config;
        $this->message = new MessageObserver($this, $message);

        $backend = new BackendService($this->message);
        $backend->setServer($this->getSocketServer());
        $this->backend = $backend;
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

    public function attach(EventManagerInterface $events)
    {
        $events->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) {
            $this->message->setScheduler($event->getScheduler());
            $backend = $this->getBackend();
            $backend->startService($this->backendHost, 1, $this->backendPort);
        }, -9000);

        $events->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $event) {
            $this->message->setWorker($event->getWorker());
        }, WorkerEvent::PRIORITY_REGULAR);

        $events->attach(WorkerEvent::EVENT_LOOP, function (WorkerEvent $event) {
            $this->message->setWorker($event->getWorker());
            $this->getBackend()->checkMessages();

        }, WorkerEvent::PRIORITY_REGULAR);
    }

    public function setWorkerStatus(string $status) : bool
    {
        return true;
    }

    public function getBackend() : BackendService
    {
        return $this->backend;
    }
}