<?php

namespace Zeus\ServerService\Shared\Factory;

use Interop\Container\ContainerInterface;
use Zend\Console\Console;
use Zend\ServiceManager\Factory\AbstractFactoryInterface;
use Zeus\ServerService\ServerServiceInterface;

class AbstractServerServiceFactory implements AbstractFactoryInterface
{

    /**
     * Can the factory create an instance for the service?
     *
     * @param  \Interop\Container\ContainerInterface $container
     * @param  string $requestedName
     * @return bool
     */
    public function canCreate(ContainerInterface $container, $requestedName)
    {
        if (!class_exists($requestedName)) {
            return false;
        }

        $class = new \ReflectionClass($requestedName);

        if ($class->implementsInterface(ServerServiceInterface::class)) {
            return true;
        }
    }

    /**
     * Create an object
     *
     * @param  ContainerInterface $container
     * @param  string $requestedName
     * @param  null|array $options
     * @return object
     * @throws \Zend\ServiceManager\Exception\ServiceNotFoundException if unable to resolve the service.
     * @throws \Zend\ServiceManager\Exception\ServiceNotCreatedException if an exception is raised when
     *     creating a service.
     * @throws \Interop\Container\Exception\ContainerException if any other error occurs
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $config = isset($options['config']) ? $options['config'] : [];
        
        $adapter = new $requestedName($config['service_settings'], $options['scheduler_adapter'], $options['logger_adapter']);

        return $adapter;
    }
}