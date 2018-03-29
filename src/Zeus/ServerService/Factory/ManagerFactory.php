<?php

namespace Zeus\ServerService\Factory;

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use LogicException;
use RuntimeException;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\FactoryInterface;
use Zeus\Kernel\Scheduler\Helper\PluginFactory;
use Zeus\Module;
use Zeus\ServerService\Manager;
use Zeus\ServerService\Shared\Logger\LoggerInterface;
use Zeus\Kernel\SchedulerInterface;
use Zeus\ServerService\ServerServiceInterface;

/**
 * Class ManagerFactory
 * @package Zeus\Kernel\Scheduler\Factory
 * @internal
 */
final class ManagerFactory implements FactoryInterface
{
    use PluginFactory;

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
        $eventManager = $container->get('zeus-event-manager');
        $config = $container->get('configuration');
        /** @var \Zend\Log\LoggerInterface $mainLogger */
        $mainLogger = $container->build(LoggerInterface::class, ['service_name' => 'main']);

        $configs = $config['zeus_process_manager']['services'];
        $services = [];

        $vmName = defined('HHVM_VERSION') ? 'HHVM' : 'PHP';
        $mainLogger->notice(sprintf("Starting ZEUS for PHP ecosystem (version %s)", Module::MODULE_VERSION));
        $mainLogger->info(sprintf("Running on %s-%s using %s %s", php_uname('s'), php_uname('r'), $vmName, phpversion()));
        $mainLogger->info("Scanning configuration for services...");
        /** @var Manager $manager */
        $manager = new $requestedName($options ? $options : []);
        $manager->setLogger($mainLogger);
        $manager->setEventManager($eventManager);
        $this->startPlugins($container, $manager, isset($config['zeus_process_manager']['manager']['plugins']) ? $config['zeus_process_manager']['manager']['plugins'] : []);

        foreach ($configs as $serviceConfig) {
            $serviceName = $serviceConfig['service_name'];

            $services[$serviceName] = function() use ($serviceConfig, $container, $mainLogger) {
                $serviceAdapter = $serviceConfig['service_adapter'];
                $serviceName = $serviceConfig['service_name'];

                if (!is_subclass_of($serviceAdapter, ServerServiceInterface::class)) {
                    throw new RuntimeException("Service $serviceAdapter must implement " . ServerServiceInterface::class);
                }

                $loggerAdapter = isset($serviceConfig['logger_adapter']) ? $serviceConfig['logger_adapter'] : LoggerInterface::class;
                /** @var LoggerInterface $serviceLogger */
                $serviceLogger = $container->build($loggerAdapter, ['service_name' => $serviceName]);

                /** @var SchedulerInterface $scheduler */
                $scheduler = $container->build(SchedulerInterface::class, [
                    'scheduler_name' => $serviceConfig['scheduler_name'],
                    'service_name' => $serviceName,
                    'logger_adapter' => $serviceLogger
                    ]
                );

                if (!$container->has($serviceAdapter)) {
                    throw new LogicException("No such service $serviceName");
                }

                $service = $container->build($serviceAdapter, [
                    'scheduler_adapter' => $scheduler,
                    'config' => $serviceConfig,
                    'logger_adapter' => $serviceLogger,
                    'service_name' => $serviceName
                ]);

                return $service;
            };

            $autoStart = isset($serviceConfig['auto_start']) ? $serviceConfig['auto_start'] : true;
            $manager->registerService($serviceName, $services[$serviceName], $autoStart);
        }

        $mainLogger->info(sprintf("Found %d service(s): %s",
            count($services) + count($manager->getBrokenServices()),
            implode(", ", array_merge(array_keys($services), array_keys($manager->getBrokenServices())))));

        return $manager;
    }
}