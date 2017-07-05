<?php

namespace Zeus\Kernel\ProcessManager\Factory;

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;
use Zend\ServiceManager\Factory\FactoryInterface;
use Zeus\Kernel\IpcServer\Server;
use Zeus\Kernel\ProcessManager\Config;
use Zeus\Kernel\ProcessManager\Helper\PluginFactory;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\LruDiscipline;


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
        $eventManager = $container->build('zeus-event-manager');
        $config = $this->getSchedulerConfig($container, $options['scheduler_name']);
        $config['service_name'] = $options['service_name'];
        $configObject = new Config($config);

        $logger = $options['logger_adapter'];
        $schedulerDiscipline =
            isset($config['scheduler_discipline']) ? $container->get($config['scheduler_discipline']) : $container->get(LruDiscipline::class);

        /** @var Process $processService */
        $processService = $container->build(Process::class, ['logger_adapter' => $logger, 'scheduler_config' => $configObject]);

        $scheduler = new Scheduler($configObject, $processService, $options['ipc_adapter'], $schedulerDiscipline);
        $scheduler->setLogger($logger);
        $scheduler->setEventManager($eventManager);

        $ipcServer = new Server();
        $ipcServer->setIpc($options['ipc_adapter']);
        $ipcServer->setEventManager($eventManager);
        $ipcServer->attach($eventManager);

        // @todo: remove this from here
        $processService->setIpc($scheduler->getIpc());

        $container->build($config['multiprocessing_module'], ['scheduler' => $scheduler]);
        $this->startPlugins($container, $scheduler, isset($config['plugins']) ? $config['plugins'] : []);

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