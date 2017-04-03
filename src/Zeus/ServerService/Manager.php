<?php

namespace Zeus\ServerService;

use Zend\Log\LoggerInterface;
use Zeus\Kernel\ProcessManager\Helper\EventManager;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\ServerServiceInterface;

final class Manager
{
    use EventManager;

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
        $this->attach();
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
     * @return $this
     */
    protected function attach()
    {
        $events = $this->getEventManager();

        $this->eventHandles[] = $events->attach(ManagerEvent::EVENT_MANAGER_INIT, function (ManagerEvent $e) {
            $this->setEvent($e);
        }, -10000);
    }

    /**
     * @param ManagerEvent $event
     * @return $this
     */
    protected function setEvent(ManagerEvent $event)
    {
        $this->event = $event;

        return $this;
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
     * @param ServerServiceInterface $service
     * @param bool $autoStart
     * @return $this
     */
    public function registerService($serviceName, ServerServiceInterface $service, $autoStart)
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
     */
    public function registerBrokenService($serviceName, $ex)
    {
        $this->brokenServices[$serviceName] = $ex;
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
        $event = $this->getEvent();

        $event->setName(ManagerEvent::EVENT_MANAGER_INIT);
        $this->getEventManager()->triggerEvent($event);

        $this->doStartService($serviceName);

        return $this;
    }

    /**
     * @param string $serviceName
     * @return $this
     */
    protected function doStartService($serviceName)
    {
        $service = $this->getService($serviceName);
        $this->eventHandles[] = $service->getScheduler()->getEventManager()->attach(SchedulerEvent::EVENT_SCHEDULER_STOP,
            function (SchedulerEvent $e) use ($service) {
                $this->onServiceStop($service);
            }, -10000);

        $service->start();
        $schedulerPid = $service->getScheduler()->getId();
        $this->logger->debug('Scheduler running as process #' . $schedulerPid);
        $this->pidToServiceMap[$schedulerPid] = $service;

        $this->servicesRunning++;


        $event = $this->getEvent();
        $event->setName(ManagerEvent::EVENT_SERVICE_START);
        $event->setError(null);
        $event->setService($service);
        $event->stopPropagation(false);
        $this->getEventManager()->triggerEvent($event);
    }

    public function startServices($serviceNames)
    {
        $event = $this->getEvent();

        $event->setName(ManagerEvent::EVENT_MANAGER_INIT);
        $this->getEventManager()->triggerEvent($event);

        $startTime = microtime(true);

        foreach ($serviceNames as $service) {
            $this->doStartService($service);
        }

        $now = microtime(true);
        $phpTime = $now - (float) $_SERVER['REQUEST_TIME_FLOAT'];
        $managerTime = $now - $startTime;

        $servicesRunning = count($serviceNames);

        $engine = defined("HHVM_VERSION") ? 'HHVM' : 'PHP';
        $this->logger->info(sprintf("Started %d services in %.2f seconds ($engine running for %.2f)", $servicesRunning, $managerTime, $phpTime));
        if (count($serviceNames) === 0) {
            $this->logger->err('No server service started');

            return;
        }

        // @todo: get rid of this loop!!
        while ($this->servicesRunning > 0) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
            if (!$pid) {
                sleep(1);
            }

            $service = $this->findServiceByPid($pid);

            if ($service) {
                $this->onServiceStop($service);
            }
        }
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