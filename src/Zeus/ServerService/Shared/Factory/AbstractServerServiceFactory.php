<?php

namespace Zeus\ServerService\Shared\Factory;

use Interop\Container\ContainerInterface;

use ReflectionClass;
use Zend\ServiceManager\Factory\AbstractFactoryInterface;
use Zeus\Kernel\Scheduler\Helper\PluginFactory;
use Zeus\ServerService\ServerServiceInterface;

use function class_exists;

class AbstractServerServiceFactory implements AbstractFactoryInterface
{
    use PluginFactory;

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

        $class = new ReflectionClass($requestedName);

        return $class->implementsInterface(ServerServiceInterface::class);
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

        $this->startPlugins($container, $options['scheduler_adapter'], isset($config['plugins']) ? $config['plugins'] : []);

        return $adapter;
    }
}