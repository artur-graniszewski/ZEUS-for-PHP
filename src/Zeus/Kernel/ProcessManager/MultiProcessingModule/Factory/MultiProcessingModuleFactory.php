<?php

namespace Zeus\Kernel\ProcessManager\MultiProcessingModule\Factory;

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Zend\Log\LoggerInterface;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\FactoryInterface;

use Zeus\Kernel\ProcessManager\MultiProcessingModule\MultiProcessingModuleInterface;
use Zeus\Kernel\ProcessManager\Scheduler;

class MultiProcessingModuleFactory implements FactoryInterface
{

    /**
     * Create an object
     *
     * @param  ContainerInterface $container
     * @param  string $requestedName
     * @param  null|array $options
     * @return object
     * @throws ServiceNotFoundException if unable to resolve the service.
     * @throws ServiceNotCreatedException if an exception is raised when
     *     creating a service.
     * @throws ContainerException if any other error occurs
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        /** @var LoggerInterface $logger */
        $logger = $options['logger_adapter'];

        /** @var Scheduler $scheduler */
        $scheduler = $options['scheduler'];
        /** @var MultiProcessingModuleInterface $driver */
        $driver = new $requestedName();
        $driver->setLogger($logger);
        $scheduler->setMultiProcessingModule($driver);
        $logger->info(sprintf("Using %s MPM module", substr($requestedName, strrpos($requestedName, '\\')+1)));

        return $driver;
    }
}