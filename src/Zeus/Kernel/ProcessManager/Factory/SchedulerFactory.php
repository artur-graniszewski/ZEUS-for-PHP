<?php

namespace Zeus\Kernel\ProcessManager\Factory;

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\FactoryInterface;
use Zeus\Kernel\ProcessManager\Helper\PluginFactory;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\LruDiscipline;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Shared\Logger\LoggerInterface;

class SchedulerFactory implements FactoryInterface
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
        $schedulerEvent = new SchedulerEvent();

        $schedulerConfig = $this->getSchedulerConfig($container, $options['scheduler_name']);
        $schedulerConfig['service_name'] = $options['service_name'];

        $serviceLoggerAdapter = $options['service_logger_adapter'];
        $mainLoggerAdapter = $options['main_logger_adapter'];
        $schedulerDiscipline =
            isset($schedulerConfig['scheduler_discipline']) ? $container->get($schedulerConfig['scheduler_discipline']) : $container->get(LruDiscipline::class);

        $processService = $container->build(Process::class, ['logger_adapter' => $serviceLoggerAdapter, 'process_event' => $schedulerEvent]);

        $scheduler = new Scheduler($schedulerConfig, $processService, $mainLoggerAdapter, $options['ipc_adapter'], $schedulerEvent, $schedulerDiscipline);
        $container->build($schedulerConfig['multiprocessing_module'], ['scheduler' => $scheduler, 'scheduler_event' => $schedulerEvent]);
        $this->startPlugins($container, $scheduler, isset($schedulerConfig['plugins']) ? $schedulerConfig['plugins'] : []);

        return $scheduler;
    }

    /**
     * @param ContainerInterface $container
     * @param string $schedulerName
     * @return mixed[]
     */
    protected function getSchedulerConfig(ContainerInterface $container, $schedulerName)
    {
        $config = $container->get('configuration');
        $schedulerConfigs = $config['zeus_process_manager']['schedulers'];
        foreach ($schedulerConfigs as $config) {
            if ($config['scheduler_name'] === $schedulerName) {
                return $config;
            }
        }
        throw new \RuntimeException("Missing scheduler configuration for $schedulerName");
    }
}