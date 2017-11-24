<?php

namespace Zeus\Kernel\Scheduler\Factory;

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\FactoryInterface;

use Zeus\Kernel\Scheduler\Worker;

class WorkerFactory implements FactoryInterface
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
        $schedulerConfig = $options['scheduler_config'];
        $worker = new Worker();
        $worker->setLogger($options['logger_adapter']);
        $worker->setConfig($schedulerConfig);
        $worker->setIpc($options['ipc_server']);

        return $worker;
    }
}