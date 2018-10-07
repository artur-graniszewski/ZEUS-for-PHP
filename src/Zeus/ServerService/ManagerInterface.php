<?php

namespace Zeus\ServerService;

use Throwable;

interface ManagerInterface
{
    public function getService(string $serviceName) : ServerServiceInterface;

    public function getServiceList(bool $isAutoStart) : array;

    /**
     * @param string $serviceName
     * @param ServerServiceInterface|\Closure $service
     * @param bool $autoStart
     */
    public function registerService(string $serviceName, $service, bool $autoStart);

    public function registerBrokenService(string $serviceName, Throwable $exception);

    /**
     * @return Throwable[]
     */
    public function getBrokenServices() : array;

    public function startService(string $serviceName);

    /**
     * @param string[] $serviceNames
     */
    public function startServices(array $serviceNames);

    /**
     * @param ServerServiceInterface[] $services
     * @param bool $mustBeRunning
     * @return int Amount of services which Manager was unable to stop
     * @throws Throwable
     */
    public function stopServices(array $services, bool $mustBeRunning);

    public function stopService(string $serviceName);

    /**
     * @param string $serviceName
     * @return mixed[]
     */
    public function getServiceConfig($serviceName);

    /**
     * @param string $serviceName
     * @param object $statusDecorator
     * @return mixed
     * @internal
     */
    public function getServiceStatus($serviceName, $statusDecorator);
}