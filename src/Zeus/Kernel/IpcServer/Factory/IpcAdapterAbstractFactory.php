<?php

namespace Zeus\Kernel\IpcServer\Factory;

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\AbstractFactoryInterface;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;

final class IpcAdapterAbstractFactory implements AbstractFactoryInterface
{

    /**
     * Can the factory create an instance for the service?
     *
     * @param  ContainerInterface $container
     * @param  string $requestedName
     * @return bool
     */
    public function canCreate(ContainerInterface $container, $requestedName)
    {
        if (!class_exists($requestedName)) {
            return false;
        }

        $class = new \ReflectionClass($requestedName);

        return $class->implementsInterface(IpcAdapterInterface::class);
    }

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
        $channelName = $options['service_name'];

        $config = isset($options['config']) ? $options['config'] : [];

        $adapter = new $requestedName($channelName, $config);

        return $adapter;
    }
}