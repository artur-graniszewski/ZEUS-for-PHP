<?php

namespace Zeus\ServerService;

use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler\Helper\PluginRegistry;
use Zeus\Kernel\Scheduler\SchedulerEvent;

final class Manager
{
    use PluginRegistry;

    /** @var ServerServiceInterface[] */
    protected $services;

    /** @var \Exception[] */
    protected $brokenServices = [];

    protected $eventHandles;

    /** @var ManagerEvent */
    protected $event;

    /** @var LoggerInterface */
    protected $logger;

    /** @var int */
    protected $servicesRunning = 0;

    /** @var ServerServiceInterface[] */
    protected $pidToServiceMap = [];

    /** @var EventManagerInterface */
    protected $events;

    public function __construct(array $services)
    {
        $this->services = $services;
    }

    public function __destruct()
    {
        if ($this->eventHandles) {
            $events = $this->getEventManager();
            foreach ($this->eventHandles as $handle) {
                $events->detach($handle);
            }
        }
    }

    /**
     * @return mixed[]|false
     */
//    protected function checkSignal()
//    {
//        $pid = pcntl_waitpid(-1, $status, WNOHANG);
//
//        if ($pid > 0) {
//            return ['pid' => $pid, 'status' => $status];
//        }
//
//        return false;
//    }

    /**
     * @return $this
     */
    protected function attach()
    {
        $events = $this->getEventManager();

        $this->eventHandles[] = $events->attach(ManagerEvent::EVENT_MANAGER_LOOP, function () {

            return;
            //$signal = $this->checkSignal();

//            if (!$signal) {
//                sleep(1);
//            }

            $service = $this->findServiceByPid($signal['pid']);

            if ($service) {
                $this->onServiceStop($service);
            }
        }, -10000);

        return $this;
    }

    /**
     * @return ManagerEvent
     */
    protected function getEvent()
    {
        if (!$this->event) {
            $this->event = new ManagerEvent();
            $this->event->setTarget($this);
        }

        return $this->event;
    }

    /**
     * @param string $serviceName
     * @return ServerServiceInterface
     */
    public function getService($serviceName)
    {
        if (!isset($this->services[$serviceName]['service'])) {
            throw new \RuntimeException("Service \"$serviceName\" not found");
        }

        $service = $this->services[$serviceName]['service'];
        $this->services[$serviceName]['service'] = ($service instanceof ServerServiceInterface ? $service : $service());

        return $this->services[$serviceName]['service'];
    }

    /**
     * @param bool $isAutoStart
     * @return string[]
     */
    public function getServiceList($isAutoStart)
    {
        $services = [];

        foreach ($this->services as $serviceName => $service) {
            if (!$isAutoStart || ($isAutoStart && $service['auto_start'])) {
                $services[] = $serviceName;
            }
        }
        return $services;
    }

    /**
     * @param string $serviceName
     * @param ServerServiceInterface|\Closure $service
     * @param bool $autoStart
     * @return $this
     */
    public function registerService($serviceName, $service, $autoStart)
    {
        $this->services[$serviceName] = [
            'service' => $service,
            'auto_start' => $autoStart,
        ];

        return $this;
    }

    /**
     * @param string $serviceName
     * @param \Exception|\Throwable $exception
     * @return $this
     */
    public function registerBrokenService($serviceName, $exception)
    {
        $this->brokenServices[$serviceName] = $exception;
        $this->logger->err(sprintf("Unable to start %s, service is broken: %s", $serviceName, $exception->getMessage()));

        return $this;
    }

    /**
     * @return \Exception[]
     */
    public function getBrokenServices()
    {
        return $this->brokenServices;
    }

    /**
     * @param string $serviceName
     * @return $this
     */
    public function startService($serviceName)
    {
        $this->startServices([$serviceName]);

        return $this;
    }

    /**
     * @param string $serviceName
     * @return $this
     */
    protected function doStartService($serviceName)
    {
        $service = $this->getService($serviceName);

        /** @var EventManager $eventManager */
        $eventManager = $service->getScheduler()->getEventManager();

        $event = $this->getEvent();
        $event->setName(ManagerEvent::EVENT_SERVICE_START);
        $event->setError(null);
        $event->setService($service);

        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_START,
            function (SchedulerEvent $event) use ($service) {
                $schedulerPid = $event->getTarget()->getProcessId();
                $this->logger->debug(sprintf('Scheduler running as process #%d', $schedulerPid));
                $this->pidToServiceMap[$schedulerPid] = $service;
                $this->servicesRunning++;
            }, -10000);

        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_SCHEDULER_STOP,
            function () use ($service) {
                $this->onServiceStop($service);
            }, -10000);


        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_KERNEL_LOOP,
            function (SchedulerEvent $schedulerEvent) use ($service, $event) {
                if (!$event->propagationIsStopped()) {
                    pcntl_signal_dispatch(); //@todo: URGENT! REPLACE me with something more platform agnostic!
                    $event->setName(ManagerEvent::EVENT_MANAGER_LOOP);
                    $this->getEventManager()->triggerEvent($event);
                } else {
                    $schedulerEvent->getTarget()->setSchedulerActive(false);
                }
            }, -10000);

        $exception = null;
        try {
            $this->getEventManager()->triggerEvent($event);

            $service->start();
        } catch (\Throwable $exception) {
            $this->registerBrokenService($serviceName, $exception);
        }

        return $this;
    }

    /**
     * @param string|string[] $serviceNames
     * @return $this
     */
    public function startServices($serviceNames)
    {
        $plugins = $this->getPluginRegistry()->count();
        $this->logger->info(sprintf("Service Manager started with %d plugin%s", $plugins, $plugins !== 1 ? 's' : ''));

        $this->logger->notice(sprintf("Starting %d service%s: %s", count($serviceNames), count($serviceNames) !== 1 ? 's' : '', implode(", ", $serviceNames)));


        $event = $this->getEvent();

        $this->attach();

        $event->setName(ManagerEvent::EVENT_MANAGER_INIT);
        $this->getEventManager()->triggerEvent($event);

        $startTime = microtime(true);

        $now = microtime(true);
        $engine = defined("HHVM_VERSION") ? 'HHVM' : 'PHP';
        $phpTime = $now - (float) $_SERVER['REQUEST_TIME_FLOAT'];
        $managerTime = $now - $startTime;

        foreach ($serviceNames as $serviceName) {
            $this->eventHandles[] = $this->getService($serviceName)->getScheduler()->getEventManager()->attach(SchedulerEvent::EVENT_SCHEDULER_START,
                function () use ($serviceName, $managerTime, $phpTime, $engine) {

                    $this->servicesRunning++;
                    $this->logger->info(sprintf("Started %s service in %.2f seconds ($engine running for %.2fs)", $serviceName, $managerTime, $phpTime));

                }, -10000);

            $this->doStartService($serviceName);
        }

        if (count($serviceNames) === count($this->brokenServices)) {
            $this->logger->err(sprintf("No server service started ($engine running for %.2fs)", $managerTime, $phpTime));
        }

        return $this;
    }

    /**
     * @param ServerServiceInterface[] $services
     * @param bool $mustBeRunning
     * @return int Amount of services which Manager was unable to stop
     * @throws \Throwable
     */
    public function stopServices($services, $mustBeRunning)
    {
        $this->logger->info(sprintf("Stopping services"));
        $servicesAmount = 0;
        $servicesStopped = 0;
        foreach ($services as $service) {
            $servicesAmount++;
            try {
                $this->stopService($service);
                $servicesStopped++;
            } catch (\Throwable $exception) {
                if ($mustBeRunning) {
                    throw $exception;
                }
            }
        }

        if ($servicesAmount !== $servicesStopped) {
            $this->logger->warn(sprintf("Only %d out of %d services were stopped gracefully", $servicesStopped, $servicesAmount));
        }

        $this->logger->notice(sprintf("Stopped %d service(s)", $servicesStopped));

        return $servicesAmount - $servicesStopped;
    }

    /**
     * @param string $serviceName
     * @return $this
     */
    public function stopService($serviceName)
    {
        $service = $this->getService($serviceName);
        $service->stop();

        $this->onServiceStop($service);

        return $this;
    }

    /**
     * @param ServerServiceInterface $service
     * @return $this
     */
    protected function onServiceStop(ServerServiceInterface $service)
    {
        $this->servicesRunning--;

        $event = $this->getEvent();
        $event->setName(ManagerEvent::EVENT_SERVICE_STOP);
        $event->setError(null);
        $event->setService($service);
        $this->getEventManager()->triggerEvent($event);

        if ($this->servicesRunning === 0) {
            $this->logger->info("All services exited");
        }

        return $this;
    }

    /**
     * @param string $serviceName
     * @return mixed[]
     */
    public function getServiceConfig($serviceName)
    {
        $service = $this->getService($serviceName);

        return $service->getConfig();
    }

    /**
     * @param string $serviceName
     * @param object $statusDecorator
     * @return mixed
     * @internal
     */
    public function getServiceStatus($serviceName, $statusDecorator)
    {
        $service = $this->getService($serviceName);
        $status = $statusDecorator->getStatus($service);

        return $status;
    }

    /**
     * @param int $pid
     * @return null|ServerServiceInterface
     */
    protected function findServiceByPid($pid)
    {
        if (!isset($this->pidToServiceMap[$pid])) {
            return null;
        }

        $service = $this->pidToServiceMap[$pid];

        return $service;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @param EventManagerInterface $events
     * @return $this
     */
    public function setEventManager(EventManagerInterface $events)
    {
        $events->setIdentifiers(array(
            __CLASS__,
            get_called_class(),
        ));
        $this->events = $events;

        return $this;
    }

    /**
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        if (null === $this->events) {
            $this->setEventManager(new EventManager());
        }

        return $this->events;
    }
}