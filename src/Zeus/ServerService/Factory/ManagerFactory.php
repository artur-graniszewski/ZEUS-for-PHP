<?php

namespace Zeus\ServerService\Factory;

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\FactoryInterface;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\ProcessManager\Helper\PluginFactory;
use Zeus\ServerService\ManagerEvent;
use Zeus\ServerService\Shared\Logger\LoggerInterface;
use Zeus\ServerService\Manager;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\ServerService\ServerServiceInterface;

/**
 * Class ManagerFactory
 * @package Zeus\Kernel\ProcessManager\Factory
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
        $config = $container->get('configuration');
        $mainLogger = $container->build(LoggerInterface::class, ['service_name' => 'main']);

        $configs = $config['zeus_process_manager']['services'];
        $services = [];

        $mainLogger->info("Scanning configuration for services...");
        $manager = new $requestedName($options ? $options : []);
        $manager->setLogger($mainLogger);
        $this->startPlugins($container, $manager, isset($config['zeus_process_manager']['manager']['plugins']) ? $config['zeus_process_manager']['manager']['plugins'] : []);

        foreach ($configs as $serviceConfig) {
            $serviceName = $serviceConfig['service_name'];

            $services[$serviceName] = function() use ($serviceConfig, $container, $mainLogger) {
                $serviceAdapter = $serviceConfig['service_adapter'];
                $serviceName = $serviceConfig['service_name'];
                $ipcAdapter = $container->build(IpcAdapterInterface::class, ['service_name' => $serviceName, 'logger_adapter' => $mainLogger]);

                if (!is_subclass_of($serviceAdapter, ServerServiceInterface::class)) {
                    throw new \RuntimeException("Service $serviceAdapter must implement " . ServerServiceInterface::class);
                }

                $loggerAdapter = isset($serviceConfig['logger_adapter']) ? $serviceConfig['logger_adapter'] : LoggerInterface::class;
                $serviceLogger = $container->build($loggerAdapter, ['service_name' => $serviceName]);

                /** @var Scheduler $scheduler */
                $scheduler = $container->build(Scheduler::class, [
                    'scheduler_name' => $serviceConfig['scheduler_name'],
                    'service_name' => $serviceName,
                    'logger_adapter' => $serviceLogger,
                    'ipc_adapter' => $ipcAdapter
                    ]
                );

                if (!$container->has($serviceAdapter)) {
                    throw new \LogicException("No such service $serviceName");
                }

                $service = $container->build($serviceAdapter, [
                    'scheduler_adapter' => $scheduler,
                    'config' => $serviceConfig,
                    'logger_adapter' => $serviceLogger,
                    'ipc_adapter' => $ipcAdapter,
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