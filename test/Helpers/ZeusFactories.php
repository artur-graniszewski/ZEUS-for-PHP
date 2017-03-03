<?php

namespace ZeusTest\Helpers;

use Zend\EventManager\EventInterface;
use Zend\Log\Logger;
use Zend\ModuleManager\ModuleManager;
use Zend\Mvc\Service\EventManagerFactory;
use Zend\Mvc\Service\ModuleManagerFactory;
use Zend\Mvc\Service\ServiceListenerFactory;
use Zend\ServiceManager\ServiceManager;
use Zend\Stdlib\ArrayUtils;
use Zeus\Controller\Factory\ZeusControllerFactory;
use Zeus\Controller\ZeusController;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\IpcServer\Factory\IpcAdapterAbstractFactory;
use Zeus\Kernel\IpcServer\Factory\IpcServerFactory;
use Zeus\Kernel\ProcessManager\Factory\ManagerFactory;
use Zeus\Kernel\ProcessManager\Factory\ProcessFactory;
use Zeus\Kernel\ProcessManager\Factory\SchedulerFactory;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\EventsInterface;
use Zeus\ServerService\Manager;
use Zeus\ServerService\Shared\Factory\AbstractServerServiceFactory;
use Zeus\ServerService\Shared\Logger\IpcLogWriter;

trait ZeusFactories
{
    /**
     * @return ServiceManager
     */
    public function getServiceManager()
    {
        $sm = new ServiceManager();
        $sm->addAbstractFactory(IpcAdapterAbstractFactory::class);
        $sm->addAbstractFactory(AbstractServerServiceFactory::class);
        $sm->setFactory(Scheduler::class, SchedulerFactory::class);
        $sm->setFactory(Process::class, ProcessFactory::class);
        $sm->setFactory(IpcAdapterInterface::class, IpcServerFactory::class);
        $sm->setFactory(DummyServiceFactory::class, DummyServiceFactory::class);
        $sm->setFactory(ZeusController::class, ZeusControllerFactory::class);
        $sm->setFactory(Manager::class, ManagerFactory::class);
        $sm->setFactory('ServiceListener', ServiceListenerFactory::class);
        $sm->setFactory('EventManager', EventManagerFactory::class);
        $sm->setFactory('ModuleManager', ModuleManagerFactory::class);
        $sm->setService('ApplicationConfig', [
            'modules' => [
            ],
            'module_listener_options' => [
                'config_glob_paths' => [realpath(__DIR__) . '/autoload/{,*.}{global,local}-development.php'],
                'config_cache_enabled' => false,
                'module_map_cache_enabled' => false,
            ]
        ]);
        $config = require realpath(__DIR__ . "/../../config/module.config.php");

        $config = ArrayUtils::merge($config,
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
                            'enable_process_cache' => true
                        ]
                    ]
                ]
            ]
        );

        $sm->setService('configuration', $config);

        return $sm;
    }

    /**
     * @param int $mainLoopIterations
     * @param callback $loopCallback
     * @return Scheduler
     */
    public function getScheduler($mainLoopIterations = 0, $loopCallback = null)
    {
        $sm = $this->getServiceManager();

        $ipcAdapter = $sm->build(DummyIpcAdapter::class, ['service_name' => 'test-service']);
        $logger = new Logger();
        $ipcWriter = new IpcLogWriter();
        $ipcWriter->setIpcAdapter($ipcAdapter);
        $logger->addWriter($ipcWriter);

        $scheduler = $sm->build(Scheduler::class, [
            'ipc_adapter' => $ipcAdapter,
            'service_name' => 'test-service',
            'scheduler_name' => 'test-scheduler',
            'service_logger_adapter' => $logger,
            'main_logger_adapter' => $logger,
        ]);

        if ($mainLoopIterations > 0) {
            $events = $scheduler->getEventManager();
            $events->attach(EventsInterface::ON_SCHEDULER_LOOP, function (EventInterface $e) use (&$mainLoopIterations, $loopCallback) {

                $mainLoopIterations--;

                if ($mainLoopIterations === 0) {
                    $e->getTarget()->setContinueMainLoop(false);
                }

                if ($loopCallback) {
                    $loopCallback($e->getTarget());
                }
            });
        }

        return $scheduler;
    }
}