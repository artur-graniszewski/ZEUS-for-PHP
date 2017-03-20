<?php

namespace Zeus\ServerService\Memcache\Factory;

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Zend\Cache\Storage\StorageInterface;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\FactoryInterface;
use Zeus\ServerService\Memcache\Message\Message;
use Zeus\ServerService\Memcache\Service;
use Zeus\ServerService\Shared\Exception\PrerequisitesNotMetException;

class MemcacheFactory implements FactoryInterface
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
        if (!class_exists('\Zend\Cache\StorageFactory')) {
            throw new PrerequisitesNotMetException("zendframework/zend-cache component must be installed first");
        }
        $config = isset($options['config']) ? $options['config'] : [];

        /** @var StorageInterface $internalCache */
        $internalCache = $container->build($config['service_settings']['internal_cache']);
        $internalCache->getOptions()->setNamespace($options['service_name'] . '_internal');
        /** @var StorageInterface $userCache */
        $userCache = $container->build($config['service_settings']['user_cache']);
        $userCache->getOptions()->setNamespace($options['service_name'] . '_user');

        $message = new Message($internalCache, $userCache);

        $adapter = new Service($config['service_settings'], $options['scheduler_adapter'], $options['logger_adapter']);
        $adapter->setMessageComponent($message);

        return $adapter;
    }
}