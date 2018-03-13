<?php

namespace Zeus\ServerService\Shared\Networking;

use Throwable;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerAwareTrait;
use Zend\Log\LoggerInterface;
use Zeus\Exception\UnsupportedOperationException;
use Zeus\IO\SocketServer;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\IpcServer\IpcEvent;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\System\Runtime;
use Zeus\ServerService\Shared\AbstractNetworkServiceConfig;
use Zeus\ServerService\Shared\Networking\Message\GatewayElectionMessage;
use Zeus\ServerService\Shared\Networking\Service\BackendService;
use Zeus\ServerService\Shared\Networking\Service\GatewayService;
use Zeus\ServerService\Shared\Networking\Service\RegistratorService;
use Zeus\ServerService\Shared\Networking\Service\WorkerIPC;

use function max;
use function microtime;
use function defined;

/**
 * Class SocketMessageBroker
 * @internal
 */
final class SocketMessageBroker
{
    use LoggerAwareTrait;

    /** @var string */
    private $backendHost = 'tcp://127.0.0.3';

    /** @var bool */
    private $isBusy = false;

    /** @var bool */
    private $isFrontend = false;

    /** @var bool */
    private $isBackend = true;

    /** @var MessageComponentInterface */
    private $message;

    /** @var GatewayService */
    private $frontend;

    /** @var BackendService */
    private $backend;

    /** @var RegistratorService */
    private $registrator;

    /** @var WorkerIPC */
    private $workerIPC;

    public function __construct(AbstractNetworkServiceConfig $config, MessageComponentInterface $message, LoggerInterface $logger)
    {
        $this->setLogger($logger);
        
        $this->config = $config;
        $this->message = new MessageWrapper($this, $message);

        $backend = new BackendService($this->message);
        $backend->setServer($this->getSocketServer());
        $this->backend = $backend;

        $registrator = new RegistratorService();
        $registrator->setLogger($logger);
        $registrator->setServer($this->getSocketServer());
        $this->registrator = $registrator;

        $frontend = new GatewayService($registrator);
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

    public function getFrontend() : GatewayService
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

    public function electGatewayWorkers(IpcServer $ipc)
    {
        $config = $this->getConfig();
        $logger = $this->getLogger();
        $logger->info(sprintf('Launching server on %s%s', $config->getListenAddress(), $config->getListenPort() ? ':' . $config->getListenPort(): ''));
        $cpus = Runtime::getNumberOfProcessors();
        if (defined("HHVM_VERSION")) {
            // HHVM does not support SO_REUSEADDR ?
            $gatewaysAmount = 1;
            $logger->warn("Running single frontend service due to the lack of SO_REUSEADDR option in HHVM");
        } else {
            $gatewaysAmount = (int) max(1, $cpus / 2);
        }
        $logger->debug("Detected $cpus CPUs: electing $gatewaysAmount concurrent gateway workers");
        $ipc->send(new GatewayElectionMessage($gatewaysAmount), IpcServer::AUDIENCE_AMOUNT, $gatewaysAmount);
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
            $backend = $this->getBackend();
            $backend->startService($this->backendHost, 1, 0);
            $this->workerIPC = new WorkerIPC($event->getWorker()->getUid(), $backend->getServer()->getLocalAddress());
            $this->getRegistrator()->notifyRegistrator(RegistratorService::STATUS_WORKER_READY, $this->workerIPC);
        }, WorkerEvent::PRIORITY_REGULAR);

        $events->attach(WorkerEvent::EVENT_EXIT, function(WorkerEvent $event) {
            $backend = $this->getBackend();
            $registrator = $this->getRegistrator();
            $registrator->notifyRegistrator(RegistratorService::STATUS_WORKER_GONE, $this->workerIPC);

            if ($registrator->isRegistered()) {
                $registrator->stopService();
            }

            if ($this->isBackend) {
                $backend->stopService();
            }

        }, 1000);

        $events->getSharedManager()->attach(IpcServer::class, IpcEvent::EVENT_MESSAGE_RECEIVED, function(IpcEvent $event) {
            $message = $event->getParams();

            if (!$message instanceof GatewayElectionMessage) {
                return;
            }

            $config = $this->getConfig();
            $this->isBackend = false;
            $this->isFrontend = true;
            $this->getBackend()->stopService();

            $this->getLogger()->debug("Becoming frontend worker");
            $this->getRegistrator()->notifyRegistrator(RegistratorService::STATUS_WORKER_GONE, $this->workerIPC);
            $this->getFrontend()->startService('tcp://' . $config->getListenAddress(), 1000, $config->getListenPort());
        }, WorkerEvent::PRIORITY_FINALIZE);

        $events->attach(WorkerEvent::EVENT_CREATE, function (WorkerEvent $event) {
            $address = $this->getRegistrator()->getRegistratorAddress();
            if ($address) {
                $event->setParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM, $address);
            }
        }, 1000);

        $events->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) {
            $registrator = $this->getRegistrator();
            $registrator->startService();
            $this->getLogger()->debug("Registrator listening on: " . $registrator->getServer()->getLocalAddress());
            $registrator->registerObservers($event->getScheduler()->getReactor());
            $this->electGatewayWorkers($event->getScheduler()->getIpc());
        }, -9000);

        $events->attach(WorkerEvent::EVENT_LOOP, function (WorkerEvent $event) {
            try {
                if ($this->isBackend) {
                    $this->getBackend()->checkMessages($event->getWorker());
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
                $frontend = $this->getFrontend();
                do {
                    if ($now - $last >1) {
                        $last = $now;
                    }

                    $frontend->selectStreams();
                } while (microtime(true) - $now < 1);

            } catch (Throwable $ex) {
                $this->getLogger()->err((string) $ex);
            }
        }, WorkerEvent::PRIORITY_REGULAR);
    }

    public function getConfig() : AbstractNetworkServiceConfig
    {
        return $this->config;
    }

    public function getWorkerIPC() : WorkerIPC
    {
        return $this->workerIPC;
    }
}