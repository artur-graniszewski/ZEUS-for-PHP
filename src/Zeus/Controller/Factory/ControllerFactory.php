<?php

namespace Zeus\Controller\Factory;

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\FactoryInterface;
use Zeus\Controller\ConsoleController;
use Zeus\ServerService\Manager;
use Zeus\ServerService\Shared\Logger\LoggerInterface;

class ControllerFactory implements FactoryInterface
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
        $config = $container->get('configuration');

        $dummyConfig =
            ['services' =>
                [
                ]
            ];

        $controller = new $requestedName(
            isset($config['zeus_process_manager']['services']) ? $config['zeus_process_manager'] : $dummyConfig
        );

        $logger = $container->build(LoggerInterface::class, ['service_name' => 'main']);
        $controller->setLogger($logger);

        $controller->setManager($container->get(Manager::class));

        return $controller;
    }
}
