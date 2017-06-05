<?php

namespace ZeusTest\Helpers;

use ReflectionProperty;
use Zend\Log\Logger;
use Zend\Mvc\Service\EventManagerFactory;
use Zend\Mvc\Service\ModuleManagerFactory;
use Zend\Mvc\Service\ServiceListenerFactory;
use Zend\Mvc\Service\ServiceManagerConfig;
use Zend\ServiceManager\ServiceManager;
use Zend\Stdlib\ArrayUtils;
use Zeus\Controller\Factory\ControllerFactory;
use Zeus\Controller\ConsoleController;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\IpcServer\Adapter\SocketAdapter;
use Zeus\Kernel\IpcServer\Factory\IpcAdapterAbstractFactory;
use Zeus\Kernel\IpcServer\Factory\IpcServerFactory;
use Zeus\ServerService\Factory\ManagerFactory;
use Zeus\Kernel\ProcessManager\Factory\ProcessFactory;
use Zeus\Kernel\ProcessManager\Factory\SchedulerFactory;
use Zeus\Kernel\ProcessManager\Plugin\ProcessTitle;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\Factory\LruDisciplineFactory;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\LruDiscipline;
use Zeus\Kernel\ProcessManager\SchedulerEvent;
use Zeus\ServerService\Manager;
use Zeus\ServerService\Shared\Factory\AbstractServerServiceFactory;
use Zeus\ServerService\Shared\Logger\IpcLoggerFactory;
use Zeus\ServerService\Shared\Logger\IpcLoggerInterface;
use Zeus\ServerService\Shared\Logger\IpcLogWriter;
use Zend\Router;

trait ZeusFactories
{
    /**
     * @param mixed[] $customConfig
     * @return ServiceManager
     */
    public function getServiceManager(array $customConfig = [])
    {
        $sm = new ServiceManager();
        $sm->addAbstractFactory(IpcAdapterAbstractFactory::class);
        $sm->addAbstractFactory(AbstractServerServiceFactory::class);
        $sm->setFactory(Scheduler::class, SchedulerFactory::class);
        $sm->setFactory(Process::class, ProcessFactory::class);
        $sm->setFactory(IpcAdapterInterface::class, IpcServerFactory::class);
        $sm->setFactory(IpcLoggerInterface::class, IpcLoggerFactory::class);
        $sm->setFactory(DummyServiceFactory::class, DummyServiceFactory::class);
        $sm->setFactory(ConsoleControllerMock::class, ControllerFactory::class);
        $sm->setFactory(ConsoleController::class, ControllerFactory::class);
        $sm->setFactory(Manager::class, ManagerFactory::class);
        $sm->setFactory(LruDiscipline::class, LruDisciplineFactory::class);
        $sm->setFactory('ServiceListener', ServiceListenerFactory::class);
        $sm->setFactory('EventManager', EventManagerFactory::class);
        $sm->setFactory('ModuleManager', ModuleManagerFactory::class);
        $sm->setFactory('zeus-event-manager', EventManagerFactory::class);

        $serviceListener = new ServiceListenerFactory();
        $r = new ReflectionProperty($serviceListener, 'defaultServiceConfig');
        $r->setAccessible(true);
        $serviceConfig = $r->getValue($serviceListener);
        $serviceConfig = ArrayUtils::merge(
            $serviceConfig,
            (new Router\ConfigProvider())->getDependencyConfig()
        );
        $serviceConfig = ArrayUtils::merge(
            $serviceConfig,
            [
                'invokables' => [
                    'Request'              => 'Zend\Http\PhpEnvironment\Request',
                    'Response'             => 'Zend\Http\PhpEnvironment\Response',
                    'ViewManager'          => 'ZendTest\Mvc\TestAsset\MockViewManager',
                    'SendResponseListener' => 'ZendTest\Mvc\TestAsset\MockSendResponseListener',
                    'BootstrapListener'    => 'ZendTest\Mvc\TestAsset\StubBootstrapListener',
                ],
                'factories' => [
                    'Router' => Router\RouterFactory::class,
                ],
                'services' => [
                    'config' => [],
                    'ApplicationConfig' => [
                        'modules' => [
                            'Zend\Router',
                            'Zeus',
                        ],
                        'module_listener_options' => [
                            'config_cache_enabled' => false,
                            'cache_dir'            => 'data/cache',
                            'module_paths'         => [],
                        ],
                    ],
                ],
            ]
        );

        $moduleConfig = require realpath(__DIR__ . "/../../config/module.config.php");

        $serviceConfig = ArrayUtils::merge($serviceConfig, $moduleConfig);

        $serviceConfig = ArrayUtils::merge($serviceConfig,
            [
                'zeus_process_manager' => [
                    'logger' => [
                        'output' => __DIR__ . '/../tmp/test.log'
                    ],
                    'schedulers' => [
                        'test_scheduler_1' => [
                            'scheduler_name' => 'test-scheduler',
                            'multiprocessing_module' => DummyServiceFactory::class,
                            'max_processes' => 32,
                            'max_process_tasks' => 100,
                            'min_spare_processes' => 3,
                            'max_spare_processes' => 5,
                            'start_processes' => 8,
                            'enable_process_cache' => true,
                            'plugins' => [
                                ProcessTitle::class,
                            ]
                        ]
                    ]
                ]
            ]
        );

        $serviceConfig = ArrayUtils::merge($serviceConfig, $customConfig);

        (new ServiceManagerConfig($serviceConfig))->configureServiceManager($sm);

        $sm->setService('configuration', $serviceConfig);

        return $sm;
    }

    /**
     * @param int $mainLoopIterations
     * @param callback $loopCallback
     * @param ServiceManager $serviceManager
     * @return Scheduler
     */
    public function getScheduler($mainLoopIterations = 0, $loopCallback = null, ServiceManager $serviceManager = null)
    {
        $sm = $serviceManager ?  $serviceManager : $this->getServiceManager();

        $ipcAdapter = $sm->build(SocketAdapter::class, ['service_name' => 'test-service']);
        $logger = new Logger();
        $ipcWriter = new IpcLogWriter();
        $ipcWriter->setIpcAdapter($ipcAdapter);
        $logger->addWriter($ipcWriter);

        $scheduler = $sm->build(Scheduler::class, [
            'ipc_adapter' => $ipcAdapter,
            'service_name' => 'test-service',
            'scheduler_name' => 'test-scheduler',
            'logger_adapter' => $logger,
        ]);

        $events = $scheduler->getEventManager();
        if ($mainLoopIterations > 0) {
            $events->attach(SchedulerEvent::EVENT_SCHEDULER_LOOP, function (SchedulerEvent $e) use (&$mainLoopIterations, $loopCallback) {
                $mainLoopIterations--;

                if ($mainLoopIterations === 0) {
                    $e->getTarget()->setContinueMainLoop(false);
                }

                if ($loopCallback) {
                    $loopCallback($e->getTarget());
                }
            });
        }

        $events->attach(SchedulerEvent::EVENT_KERNEL_LOOP, function (SchedulerEvent $e) {
            $e->getTarget()->setContinueMainLoop(false);
            $e->stopPropagation(true);
        }, 10000000);

        return $scheduler;
    }
}