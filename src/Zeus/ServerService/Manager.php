<?php

namespace Zeus\ServerService;

use Zend\Log\LoggerInterface;
use Zeus\Kernel\ProcessManager\Helper\EventManager;
use Zeus\Kernel\ProcessManager\Helper\PluginRegistry;
use Zeus\Kernel\ProcessManager\SchedulerEvent;

final class Manager
{
    use EventManager;
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
     * @return mixed[]
     */
    protected function checkSignal()
    {
        $pid = pcntl_waitpid(-1, $status, WNOHANG);

        if ($pid > 0) {
            return ['pid' => $pid, 'status' => $status];
        }
    }

    /**
     * @return $this
     */
    protected function attach()
    {
        $events = $this->getEventManager();

        $this->eventHandles[] = $events->attach(ManagerEvent::EVENT_MANAGER_LOOP, function (ManagerEvent $e) {
            $signal = $this->checkSignal();

            if (!$signal) {
                sleep(1);
            }

            $service = $this->findServiceByPid($signal['pid']);

            if ($service) {
                $this->onServiceStop($service);
            }
        }, -10000);
    }

    /**
     * @return ManagerEvent
     */
    protected function getEvent()
    {
        if (!$this->event) {
            $this->event = new ManagerEvent();
            $this->event->setManager($this);
        }

        return $this->event;
    }

        /**
     * @param string $serviceName
     * @return ServerServiceInterface
     */
    protected function getService($serviceName)
    {
        if (!isset($this->services[$serviceName]['service'])) {
            throw new \RuntimeException("Service \"$serviceName\" not found");
        }

        $service = $this->services[$serviceName]['service'];
        return ($service instanceof ServerServiceInterface ? $service : $service());
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
     * @param ServerServiceInterface|Closure $service
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
     * @param \Exception $ex
     * @return $this
     */
    public function registerBrokenService($serviceName, $ex)
    {
        $this->brokenServices[$serviceName] = $ex;
        $this->logger->err(sprintf("Unable to start %s, service is broken: %s", $serviceName, $ex->getMessage()));

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
        $plugins = $this->getPluginRegistry()->count();
        $this->logger->info(sprintf("Starting Server Service Manager with %d plugin%s", $plugins, $plugins !== 1 ? 's' : ''));

        $service = $this->getService($serviceName);
        $this->eventHandles[] = $service->getScheduler()->getEventManager()->attach(SchedulerEvent::EVENT_SCHEDULER_STOP,
            function () use ($service) {
                $this->onServiceStop($service);
            }, -10000);

        $e = null;
        try {
            $service->start();
            $schedulerPid = $service->getScheduler()->getId();
            $this->logger->debug('Scheduler running as process #' . $schedulerPid);
            $this->pidToServiceMap[$schedulerPid] = $service;
            $this->servicesRunning++;
        } catch (\Exception $e) {
            $this->registerBrokenService($serviceName, $e);

            return $this;
        } catch (\Throwable $e) {
            $this->registerBrokenService($serviceName, $e);

            return $this;
        }

        $event = $this->getEvent();
        $event->setName(ManagerEvent::EVENT_SERVICE_START);
        $event->setError(null);
        $event->setService($service);
        $event->stopPropagation(false);
        $this->getEventManager()->triggerEvent($event);

        return $this;
    }

    /**
     * @param string|string[] $serviceNames
     * @return $this
     */
    public function startServices($serviceNames)
    {
        $event = $this->getEvent();

        $this->attach();

        $event->setName(ManagerEvent::EVENT_MANAGER_INIT);
        $this->getEventManager()->triggerEvent($event);

        $startTime = microtime(true);

        foreach ($serviceNames as $service) {
            $this->doStartService($service);
        }

        $now = microtime(true);
        $phpTime = $now - (float) $_SERVER['REQUEST_TIME_FLOAT'];
        $managerTime = $now - $startTime;

        $engine = defined("HHVM_VERSION") ? 'HHVM' : 'PHP';
        $this->logger->info(sprintf("Started %d services in %.2f seconds ($engine running for %.2f)", $this->servicesRunning, $managerTime, $phpTime));
        if (count($serviceNames) === 0) {
            $this->logger->err('No server service started');

            return $this;
        }

        // @todo: get rid of this loop!!
        while ($this->servicesRunning > 0) {
            $event->setName(ManagerEvent::EVENT_MANAGER_LOOP);
            $event->setError(null);
            $event->stopPropagation(false);
            $this->getEventManager()->triggerEvent($event);
        }

        return $this;
    }

    /**
     * @param ServerServiceInterface[] $services
     * @param bool $mustBeRunning
     * @return int Amount of services which Manager was unable to stop
     * @throws \Exception
     */
    public function stopServices($services, $mustBeRunning)
    {
        $servicesAmount = 0;
        foreach ($services as $service) {
            try {
                $this->stopService($service);
                $servicesAmount++;
            } catch (\Exception $exception) {
                if ($mustBeRunning) {
                    throw $exception;
                }
            }
        }

        $servicesLeft = $servicesAmount;

        $signalInfo = [];

        if (function_exists('pcntl_sigtimedwait')) {
            while ($servicesLeft > 0 && pcntl_sigtimedwait([SIGCHLD], $signalInfo, 1)) {
                $servicesLeft--;
            }
        }

        if ($servicesLeft) {
            $this->logger->warn(sprintf("Only %d out of %d services were stopped gracefully", $servicesAmount - $servicesLeft, $servicesAmount));
        }

        $this->logger->info(sprintf("Stopped %d service(s)", $servicesAmount - $servicesLeft));

        return $servicesLeft;
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
        $event->stopPropagation(false);
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
}