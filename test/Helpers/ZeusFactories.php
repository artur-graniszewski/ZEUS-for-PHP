<?php

namespace ZeusTest\Helpers;

use ReflectionProperty;
use Zend\EventManager\EventManager;
use Zend\Log\Logger;
use Zend\Log\Writer\Noop;
use Zend\Mvc\Service\EventManagerFactory;
use Zend\Mvc\Service\ModuleManagerFactory;
use Zend\Mvc\Service\ServiceListenerFactory;
use Zend\Mvc\Service\ServiceManagerConfig;
use Zend\ServiceManager\ServiceManager;
use Zend\Stdlib\ArrayUtils;
use Zeus\Controller\Factory\ControllerFactory;
use Zeus\Controller\MainController;
use Zeus\Kernel\IpcServer;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Factory\MultiProcessingModuleFactory;
use Zeus\ServerService\Factory\ManagerFactory;
use Zeus\Kernel\Scheduler\Factory\WorkerFactory;
use Zeus\Kernel\Scheduler\Factory\SchedulerFactory;
use Zeus\Kernel\Scheduler\Plugin\ProcessTitle;
use Zeus\Kernel\Scheduler\Worker;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\Discipline\Factory\LruDisciplineFactory;
use Zeus\Kernel\Scheduler\Discipline\LruDiscipline;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\ServerService\Manager;
use Zeus\ServerService\Shared\Factory\AbstractServerServiceFactory;
use Zeus\ServerService\Shared\Logger\IpcLoggerInterface;
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
        $sm->addAbstractFactory(AbstractServerServiceFactory::class);
        $sm->setFactory(Scheduler::class, SchedulerFactory::class);
        $sm->setFactory(Worker::class, WorkerFactory::class);
        $sm->setFactory(MainControllerMock::class, ControllerFactory::class);
        $sm->setFactory(MainController::class, ControllerFactory::class);
        $sm->setFactory(Manager::class, ManagerFactory::class);
        $sm->setFactory(LruDiscipline::class, LruDisciplineFactory::class);
        $sm->setFactory('ServiceListener', ServiceListenerFactory::class);
        $sm->setFactory('EventManager', EventManagerFactory::class);
        $sm->setFactory('ModuleManager', ModuleManagerFactory::class);
        $sm->setFactory('zeus-event-manager', EventManagerFactory::class);
        $sm->setFactory(DummyMpm::class, MultiProcessingModuleFactory::class);
        $sm->setFactory(Scheduler\MultiProcessingModule\PosixProcess::class, MultiProcessingModuleFactory::class);
        $sm->setFactory(Scheduler\MultiProcessingModule\PosixThread::class, MultiProcessingModuleFactory::class);

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
                            'multiprocessing_module' => DummyMpm::class,
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

        $this->clearSharedEventManager($sm);
        $logger = new Logger();
        $writer = new Noop();
        $logger->addWriter($writer);

        /** @var Scheduler $scheduler */
        $scheduler = $sm->build(Scheduler::class, [
            'service_name' => 'test-service',
            'scheduler_name' => 'test-scheduler',
            'logger_adapter' => $logger,
        ]);

        $events = $scheduler->getEventManager();
        $sm = $events->getSharedManager();

        $ipcServer = new IpcServer();
        $ipcServer->setEventManager(new EventManager($sm));
        $ipcServer->attach(new EventManager($sm));

        if ($mainLoopIterations > 0) {
            $sm->attach('*', SchedulerEvent::EVENT_SCHEDULER_LOOP, function (SchedulerEvent $e) use (&$mainLoopIterations, $loopCallback) {
                $mainLoopIterations--;

                if ($mainLoopIterations === 0) {
                    $e->getScheduler()->setIsTerminating(true);
                }

                if ($loopCallback) {
                    $loopCallback($e->getScheduler());
                }
            }, SchedulerEvent::PRIORITY_FINALIZE + 1);
        }

        $sm->attach('*', SchedulerEvent::EVENT_KERNEL_LOOP, function (SchedulerEvent $e) {
            $e->getScheduler()->setIsTerminating(true);
            $e->stopPropagation(true);
        }, 10000000);

        $scheduler->setIsTerminating(false);
        $scheduler->setIpc($ipcServer);
        $scheduler->getMultiProcessingModule()->attach($events);
        $scheduler->getMultiProcessingModule()->setSchedulerEvent($scheduler->getSchedulerEvent());

        $worker = $scheduler->getWorkerService();
        $worker->setEventManager($events);
        $worker->setIpc($scheduler->getIpc());
        $worker->setLogger($logger);
        $worker->setConfig($scheduler->getConfig());
        $worker->setProcessId(getmypid());
        $workerEvent = new Scheduler\WorkerEvent();
        $workerEvent->setWorker($worker);
        $workerEvent->setTarget($worker);
        $scheduler->getMultiProcessingModule()->setWorkerEvent($workerEvent);

        return $scheduler;
    }

    public function clearSharedEventManager(ServiceManager $serviceManager = null)
    {
        $sm = $serviceManager ?  $serviceManager : $this->getServiceManager();
        /** @var EventManager $eventManager */
        $eventManager = $sm->build('zeus-event-manager');
        $eventManager->getSharedManager()->clearListeners('*');
    }
}