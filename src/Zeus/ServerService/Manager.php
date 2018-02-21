<?php

namespace Zeus\ServerService;

use RuntimeException;
use Throwable;
use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerAwareTrait;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\LoggerAwareTrait;
use Zend\Log\LoggerInterface;
use Zeus\Kernel\Scheduler\Helper\PluginRegistry;
use Zeus\Kernel\Scheduler\SchedulerEvent;

final class Manager
{
    use PluginRegistry;
    use EventManagerAwareTrait;
    use LoggerAwareTrait;

    /** @var ServerServiceInterface[] */
    private $services = [];

    /** @var \Exception[] */
    private $brokenServices = [];

    private $eventHandles = [];

    /** @var ManagerEvent */
    private $event;

    /** @var int */
    private $servicesRunning = 0;

    /** @var ServerServiceInterface[] */
    private $pidToServiceMap = [];

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

    private function attach()
    {
        $events = $this->getEventManager();

        $this->eventHandles[] = $events->attach(ManagerEvent::EVENT_MANAGER_LOOP, function () {

            return;

            $service = $this->findServiceByUid($signal['pid']);

            if ($service) {
                $this->onServiceStop($service);
            }
        }, -10000);
    }

    private function getEvent() : ManagerEvent
    {
        if (!$this->event) {
            $this->event = new ManagerEvent();
            $this->event->setTarget($this);
        }

        return $this->event;
    }

    public function getService(string $serviceName) : ServerServiceInterface
    {
        if (!isset($this->services[$serviceName]['service'])) {
            throw new RuntimeException("Service \"$serviceName\" not found");
        }

        $service = $this->services[$serviceName]['service'];
        $this->services[$serviceName]['service'] = ($service instanceof ServerServiceInterface ? $service : $service());

        return $this->services[$serviceName]['service'];
    }

    public function getServiceList(bool $isAutoStart) : array
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
     */
    public function registerService(string $serviceName, $service, bool $autoStart)
    {
        $this->services[$serviceName] = [
            'service' => $service,
            'auto_start' => $autoStart,
        ];
    }

    public function registerBrokenService(string $serviceName, Throwable $exception)
    {
        $this->brokenServices[$serviceName] = $exception;
        $this->getLogger()->err(sprintf("Unable to start %s, service is broken: %s", $serviceName, $exception->getMessage()));

        return $this;
    }

    /**
     * @return Throwable[]
     */
    public function getBrokenServices() : array
    {
        return $this->brokenServices;
    }

    public function startService(string $serviceName)
    {
        $this->startServices([$serviceName]);
    }

    private function doStartService(string $serviceName)
    {
        $service = $this->getService($serviceName);
        $scheduler = $service->getScheduler();

        /** @var EventManager $eventManager */
        $eventManager = $scheduler->getEventManager();

        $event = $this->getEvent();
        $event->setName(ManagerEvent::EVENT_SERVICE_START);
        $event->setError(null);
        $event->setService($service);

        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_START,
            function (SchedulerEvent $event) use ($service) {
                $this->getLogger()->debug(sprintf('Scheduler running as worker #%d', getmypid()));
                $this->pidToServiceMap[getmypid()] = $service;
                $this->servicesRunning++;
            }, -10000);

        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::EVENT_STOP,
            function () use ($service) {
                $this->onServiceStop($service);
            }, -10000);


        $this->eventHandles[] = $eventManager->attach(SchedulerEvent::INTERNAL_EVENT_KERNEL_LOOP,
            function (SchedulerEvent $schedulerEvent) use ($service, $event) {
                if (!$event->propagationIsStopped()) {
                    $event->setName(ManagerEvent::EVENT_MANAGER_LOOP);
                    $this->getEventManager()->triggerEvent($event);
                } else {
                    $schedulerEvent->getScheduler()->setTerminating(true);
                }
            }, -10000);

        $this->eventHandles[] = $eventManager->attach(ManagerEvent::EVENT_MANAGER_STOP,
            function (ManagerEvent $event) use ($scheduler) {
                $this->getLogger()->info("Service manager stopped");
            }, 10000);

        $exception = null;
        try {
            $this->getEventManager()->triggerEvent($event);

            $service->start();
        } catch (Throwable $exception) {
            $this->registerBrokenService($serviceName, $exception);
        }
    }

    /**
     * @param string|string[] $serviceNames
     */
    public function startServices($serviceNames)
    {
        $plugins = $this->getPluginRegistry()->count();
        $this->getLogger()->info(sprintf("Service Manager started with %d plugin%s", $plugins, $plugins !== 1 ? 's' : ''));
        $this->getLogger()->notice(sprintf("Starting %d service%s: %s", count($serviceNames), count($serviceNames) !== 1 ? 's' : '', implode(", ", $serviceNames)));

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
            $this->eventHandles[] = $this->getService($serviceName)->getScheduler()->getEventManager()->attach(SchedulerEvent::EVENT_START,
                function () use ($serviceName, $managerTime, $phpTime, $engine) {
                    $this->servicesRunning++;
                    $this->getLogger()->info(sprintf("Started %s service in %.2f seconds ($engine running for %.2fs)", $serviceName, $managerTime, $phpTime));

                }, -10000);

            $this->doStartService($serviceName);
        }

        if (count($serviceNames) === count($this->brokenServices)) {
            $this->getLogger()->err(sprintf("No server service started ($engine running for %.2fs)", $managerTime, $phpTime));
        }
    }

    /**
     * @param ServerServiceInterface[] $services
     * @param bool $mustBeRunning
     * @return int Amount of services which Manager was unable to stop
     * @throws Throwable
     */
    public function stopServices(array $services, bool $mustBeRunning)
    {
        $this->getLogger()->info(sprintf("Stopping services"));
        $servicesAmount = 0;
        $servicesStopped = 0;
        foreach ($services as $service) {
            $servicesAmount++;
            try {
                $this->stopService($service);
                $servicesStopped++;
            } catch (Throwable $exception) {
                if ($mustBeRunning) {
                    throw $exception;
                }
            }
        }

        if ($servicesAmount !== $servicesStopped) {
            $this->getLogger()->warn(sprintf("Only %d out of %d services were stopped gracefully", $servicesStopped, $servicesAmount));
        }


        $event = $this->getEvent();
        $event->setName(ManagerEvent::EVENT_MANAGER_STOP);
        $event->setError(null);
        $this->getEventManager()->triggerEvent($event);

        $this->getLogger()->notice(sprintf("Stopped %d service(s)", $servicesStopped));

        return $servicesAmount - $servicesStopped;
    }

    public function stopService(string $serviceName)
    {
        $service = $this->getService($serviceName);
        $service->stop();

        $this->onServiceStop($service);
    }

    private function onServiceStop(ServerServiceInterface $service)
    {
        $this->servicesRunning--;

        $event = $this->getEvent();
        $event->setName(ManagerEvent::EVENT_SERVICE_STOP);
        $event->setError(null);
        $event->setService($service);
        $this->getEventManager()->triggerEvent($event);

        if ($this->servicesRunning === 0) {
            $this->getLogger()->info("All services exited");
        }
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
     * @param int $uid
     * @return null|ServerServiceInterface
     */
    private function findServiceByUid(int $uid)
    {
        if (!isset($this->pidToServiceMap[$uid])) {
            return null;
        }

        $service = $this->pidToServiceMap[$uid];

        return $service;
    }
}