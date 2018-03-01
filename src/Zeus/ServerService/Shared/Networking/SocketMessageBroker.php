<?php

namespace Zeus\ServerService\Shared\Networking;

use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerAwareTrait;
use Zend\Log\LoggerInterface;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\Exception\IOException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;
use Zeus\ServerService\Shared\Networking\Message\FrontendElectionMessage;
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

    /** @var bool */
    private $isBusy = false;

    /** @var bool */
    private $isFrontend = false;

    /** @var bool */
    private $isBackend = true;

    /** @var MessageComponentInterface */
    private $message;

    /** @var FrontendService */
    private $frontend;

    /** @var BackendService */
    private $backend;

    /** @var RegistratorService */
    private $registrator;

    /** @var int */
    private $uid = 0;

    public function __construct(AbstractNetworkServiceConfig $config, MessageComponentInterface $message, LoggerInterface $logger)
    {
        $this->setLogger($logger);
        
        $this->config = $config;
        $this->message = new MessageWrapper($this, $message);

        $backend = new BackendService($message);
        $backend->setLogger($logger);
        $backend->setServer($this->getSocketServer());
        $this->backend = $backend;

        $registrator = new RegistratorService();
        $registrator->setLogger($logger);
        $registrator->setServer($this->getSocketServer());
        $this->registrator = $registrator;

        $frontend = new FrontendService($registrator, $config);
        $frontend->setLogger($logger);
        $frontend->setServer($this->getSocketServer());
        $this->frontend = $frontend;
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

    public function getWorkerUid() : int
    {
        return $this->uid;
    }

    public function getFrontend() : FrontendService
    {
        return $this->frontend;
    }

    public function getRegistrator() : RegistratorService
    {
        return $this->registrator;
    }

    public function getBackend() : BackendService
    {
        return $this->backend;
    }

    public function attach(EventManagerInterface $events)
    {
        $events->attach(WorkerEvent::EVENT_INIT, function (WorkerEvent $event) {
            $registrator = $this->getRegistrator();
            $registrator->setWorkerUid($event->getWorker()->getUid());
            if ($event->getParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM)) {
                $registrator->setRegistratorAddress($event->getParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM));
            }
            $registrator->register();
        }, WorkerEvent::PRIORITY_REGULAR + 1);

        $events->attach(WorkerEvent::EVENT_INIT, function(WorkerEvent $event) {
            $this->uid = $event->getWorker()->getUid();
            $this->getBackend()->startBackendServer($this->uid);
        }, WorkerEvent::PRIORITY_REGULAR);

        $events->attach(WorkerEvent::EVENT_EXIT, function(WorkerEvent $event) {
            $backend = $this->getBackend();
            $registrator = $this->getRegistrator();
            $this->getRegistrator()->notifyRegistrator(RegistratorService::STATUS_WORKER_GONE, $this->uid,0);

            if ($this->isBackend && !$backend->getServer()->isClosed()) {
                $backend->getServer()->close();
            }

            if ($backend->isClientConnected()) {
                $backend->getClientStream()->close();
            }

            if ($registrator->isRegistered()) {
                $registrator->unregister();
            }
        }, 1000);

        $events->getSharedManager()->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, function(IpcEvent $event) {
            $message = $event->getParams();

            if (!$message instanceof FrontendElectionMessage) {
                return;
            }

            $this->isBackend = false;
            $this->isFrontend = true;
            $this->getBackend()->getServer()->close();

            $this->getLogger()->debug("Becoming frontend worker");
            $this->getRegistrator()->notifyRegistrator(RegistratorService::STATUS_WORKER_GONE, $this->uid, 0);
            $this->getFrontend()->startFrontendServer(100);
        }, WorkerEvent::PRIORITY_FINALIZE);

        $events->attach(WorkerEvent::EVENT_CREATE, function (WorkerEvent $event) {
            $address = $this->getRegistrator()->getRegistratorAddress();
            if ($address) {
                $event->setParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM, $address);
            }
        }, 1000);

        $events->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) {
            $this->getRegistrator()->startRegistratorServer();
            $this->getRegistrator()->registerObservers($event->getScheduler()->getReactor());
            $this->getFrontend()->electFrontendWorkers($event->getScheduler()->getIpc());
        }, -9000);

        $events->attach(WorkerEvent::EVENT_LOOP, function (WorkerEvent $event) {
            try {
                if ($this->isBackend) {
                    if (!$this->getBackend()->isClientConnected()) {
                        $this->getRegistrator()->notifyRegistrator(RegistratorService::STATUS_WORKER_READY, $this->uid, $this->getBackend()->getServer()->getLocalPort());
                    }
                    $this->getBackend()->checkWorkerMessages($event->getWorker());
                    return;
                }

                if (!$this->isFrontend) {

                    return;
                }

                if (!$this->isBusy) {
                    $event->getWorker()->setRunning();
                    $this->isBusy = true;
                }

                static $last = 0;
                $now = microtime(true);
                do {
                    if ($now - $last >1) {
                        $last = $now;
                    }

                    $this->getFrontend()->selectStreams();
                } while (microtime(true) - $now < 1);

            } catch (IOException $ex) {
                $this->getLogger()->err((string) $ex);
            }
        }, WorkerEvent::PRIORITY_REGULAR);
    }

    public function getConfig() : AbstractNetworkServiceConfig
    {
        return $this->config;
    }
}