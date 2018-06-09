<?php

namespace ZeusTest\Helpers;

use ReflectionProperty;
use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerInterface;
use Zend\Log\Logger;
use Zend\Log\LoggerInterface;
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
use Zeus\Kernel\Scheduler\Status\WorkerState;
use Zeus\Kernel\Scheduler\WorkerEvent;
use Zeus\Kernel\SchedulerInterface;
use Zeus\ServerService\Factory\ManagerFactory;
use Zeus\Kernel\Scheduler\Factory\SchedulerFactory;
use Zeus\Kernel\Scheduler\Plugin\ProcessTitle;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\Discipline\Factory\LruDisciplineFactory;
use Zeus\Kernel\Scheduler\Discipline\LruDiscipline;
use Zeus\Kernel\Scheduler\SchedulerEvent;
use Zeus\ServerService\Manager;
use Zeus\ServerService\Shared\Factory\AbstractServerServiceFactory;
use Zend\Router;

trait ZeusFactories
{
    public function simulateWorkerInit(EventManagerInterface $events)
    {
        $events->attach(WorkerEvent::EVENT_CREATE, function(WorkerEvent $event) {
            $event->setParam(SchedulerInterface::WORKER_INIT, true);
        }, WorkerEvent::PRIORITY_INITIALIZE + 1);
    }
    /**
     * @param mixed[] $customConfig
     * @return ServiceManager
     */
    public function getServiceManager(array $customConfig = [])
    {
        $sm = new ServiceManager();
        $sm->addAbstractFactory(AbstractServerServiceFactory::class);
        $sm->setFactory(SchedulerInterface::class, SchedulerFactory::class);
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
                            'min_spare_processes' => 2,
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

    public function triggerSchedulerLoop(SchedulerInterface $scheduler)
    {
        $em = $scheduler->getEventManager();
        $event = new SchedulerEvent();
        $event->setScheduler($scheduler);
        $event->setTarget($scheduler);
        $event->setName(SchedulerEvent::EVENT_LOOP);
        // first event may register workers
        $em->triggerEvent($event);
    }

    public function getDummyLogger() : LoggerInterface
    {
        $logger = new Logger();
        $writer = new Noop();
        $logger->addWriter($writer);

        return $logger;
    }
    /**
     * @param int $mainLoopIterations
     * @param callback $loopCallback
     * @param ServiceManager $serviceManager
     * @return SchedulerInterface
     */
    public function getScheduler($mainLoopIterations = 0, $loopCallback = null, ServiceManager $serviceManager = null)
    {
        $sm = $serviceManager ?  $serviceManager : $this->getServiceManager();

        $this->clearSharedEventManager($sm);
        $logger = $this->getDummyLogger();

        /** @var Scheduler $scheduler */
        $scheduler = $sm->build(SchedulerInterface::class, [
            'service_name' => 'test-service',
            'scheduler_name' => 'test-scheduler',
            'logger_adapter' => $logger,
        ]);

        $events = $scheduler->getEventManager();
        $sm = $events->getSharedManager();

        $ipcServer = new IpcServer();
        $ipcServer->setEventManager($events);

        if ($mainLoopIterations > 0) {
            $sm->attach('*', SchedulerEvent::EVENT_LOOP, function (SchedulerEvent $e) use (&$mainLoopIterations, $loopCallback) {
                $mainLoopIterations--;

                if ($mainLoopIterations === 0) {
                    $e->getScheduler()->setTerminating(true);
                }

                if ($loopCallback) {
                    $loopCallback($e->getScheduler());
                }
            }, SchedulerEvent::PRIORITY_FINALIZE + 1);
        }

        $sm->attach('*', SchedulerEvent::INTERNAL_EVENT_KERNEL_LOOP, function (SchedulerEvent $e) {
            $e->getScheduler()->setTerminating(true);
            $e->stopPropagation(true);
        }, 10000000);

        $scheduler->setTerminating(false);
        $scheduler->setIpc($ipcServer);

        $worker = new WorkerState("test");
        $worker->setProcessId(getmypid());
        $workerEvent = new Scheduler\WorkerEvent();
        $workerEvent->setWorker($worker);
        $workerEvent->setTarget($worker);
        $workerEvent->setScheduler($scheduler);
        $scheduler->getMultiProcessingModule()->getDecorator()->setWorkerEvent($workerEvent);

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