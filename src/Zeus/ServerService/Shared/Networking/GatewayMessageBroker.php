<?php

namespace Zeus\ServerService\Shared\Networking;

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
use Zeus\Kernel\Scheduler\Command\CreateWorker;

use function max;
use function microtime;
use function defined;
use Zeus\Kernel\Scheduler\Command\InitializeWorker;
use Zeus\Kernel\Scheduler\Event\WorkerLoopRepeated;


/**
 * Class GatewayMessageBroker
 * @internal
 */
final class GatewayMessageBroker implements BrokerStrategy
{
    use LoggerAwareTrait;

    /** @var AbstractNetworkServiceConfig */
    private $config;

    /** @var string */
    private $backendHost = 'tcp://127.0.0.3';

    /** @var string */
    private $registratorHost = 'tcp://127.0.0.2';

    /** @var bool */
    private $isBusy = false;

    /** @var bool */
    private $isGateway = false;

    /** @var bool */
    private $isBackend = true;

    /** @var MessageComponentInterface */
    private $message;

    /** @var GatewayService */
    private $gateway;

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
        $this->message = new MessageObserver($this, $message);

        $backend = new BackendService($this->message);
        $backend->setServer($this->getSocketServer());
        $this->backend = $backend;

        $registrator = new RegistratorService();
        $registrator->setLogger($logger);
        $registrator->setServer($this->getSocketServer());
        $this->registrator = $registrator;

        $frontend = new GatewayService($registrator);
        $frontend->setServer($this->getSocketServer());
        $this->gateway = $frontend;
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

    public function getGateway() : GatewayService
    {
        return $this->gateway;
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
        $logger = $this->getLogger();
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

    public function setWorkerStatus(string $command) : bool
    {
        return $this->getRegistrator()->notify($command, $this->workerIPC);
    }

    public function attach(EventManagerInterface $events)
    {
        $events->attach(InitializeWorker::class, function (WorkerEvent $event) {
            $this->message->setScheduler($event->getScheduler());
            $registrator = $this->getRegistrator();
            $registrator->setWorkerUid($event->getWorker()->getUid());
            if ($event->getParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM)) {
                $registrator->setRegistratorAddress($event->getParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM));
            }
        }, WorkerEvent::PRIORITY_INITIALIZE + 3);

        $events->attach(InitializeWorker::class, function (WorkerEvent $event) {
            $registrator = $this->getRegistrator();
            $registrator->register();
        }, WorkerEvent::PRIORITY_INITIALIZE + 1);

        $events->attach(InitializeWorker::class, function(WorkerEvent $event) {
            $backend = $this->getBackend();
            $backend->startService($this->backendHost, 1, 0);
            $this->workerIPC = new WorkerIPC($event->getWorker()->getUid(), $backend->getServer()->getLocalAddress());
            $this->setWorkerStatus(RegistratorService::STATUS_WORKER_READY);
        }, WorkerEvent::PRIORITY_REGULAR);

        $events->attach(WorkerEvent::EVENT_EXIT, function() {
            $backend = $this->getBackend();
            $registrator = $this->getRegistrator();
            $this->setWorkerStatus(RegistratorService::STATUS_WORKER_GONE);

            if ($registrator->isRegistered()) {
                $registrator->stopService();
            }

            if ($this->isBackend) {
                $backend->stopService();
            }

        }, 1000);

        $events->attach(IpcEvent::EVENT_MESSAGE_RECEIVED, function(IpcEvent $event) {
            $message = $event->getParams();

            if (!$message instanceof GatewayElectionMessage) {
                return;
            }

            $config = $this->getConfig();
            $this->isBackend = false;
            $this->isGateway = true;
            $this->getBackend()->stopService();

            $this->getLogger()->debug("Switching to gateway mode");
            $this->setWorkerStatus(RegistratorService::STATUS_WORKER_GONE);
            $this->getGateway()->startService('tcp://' . $config->getListenAddress(), 1000, $config->getListenPort());
        }, WorkerEvent::PRIORITY_FINALIZE);

        $events->attach(CreateWorker::class, function (WorkerEvent $event) {
            $address = $this->getRegistrator()->getRegistratorAddress();
            if ($address) {
                $event->setParam(RegistratorService::IPC_ADDRESS_EVENT_PARAM, $address);
            }
        }, 1000);

        $events->attach(SchedulerEvent::EVENT_START, function(SchedulerEvent $event) {
            $this->message->setScheduler($event->getScheduler());
            $registrator = $this->getRegistrator();
            $registrator->startService($this->registratorHost, 1000, 0);
            $this->getLogger()->debug("Registrator listening on: " . $registrator->getServer()->getLocalAddress());
            $registrator->registerObservers($event->getScheduler()->getReactor());
            $this->electGatewayWorkers($event->getScheduler()->getIpc());
        }, -9000);

        $events->attach(WorkerLoopRepeated::class, function (WorkerEvent $event) {
            if ($this->isBackend) {
                $this->message->setWorker($event->getWorker());
                $this->getBackend()->checkMessages();
                return;
            }

            if (!$this->isGateway) {

                return;
            }

            if (!$this->isBusy) {
                $event->getWorker()->setRunning();
                $event->getScheduler()->syncWorker($event->getWorker());
                $this->isBusy = true;
            }

            static $last = 0;
            $now = microtime(true);
            $gateway = $this->getGateway();
            do {
                if ($now - $last > 1) {
                    $last = $now;
                }

                $gateway->selectStreams();
            } while (microtime(true) - $now < 1);

        }, WorkerEvent::PRIORITY_REGULAR);
    }

    public function getConfig() : AbstractNetworkServiceConfig
    {
        return $this->config;
    }
}