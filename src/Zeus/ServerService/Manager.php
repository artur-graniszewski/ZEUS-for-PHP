<?php

namespace Zeus\ServerService;

use Zeus\Kernel\ProcessManager\Helper\EventManager;
use Zeus\ServerService\ServerServiceInterface;

final class Manager
{
    use EventManager;

    /** @var ServerServiceInterface[] */
    protected $services;

    public function __construct(array $services)
    {
        $this->services = $services;
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

        return $this->services[$serviceName]['service'];
    }

    /**
     * @param bool $isAutoStart
     * @return ServerServiceInterface[]
     */
    public function getServices($isAutoStart)
    {
        $services = [];

        foreach ($this->services as $serviceName => $service) {
            if (!$isAutoStart || ($isAutoStart && $service['auto_start'])) {
                $services[$serviceName] = $service['service'];
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
}