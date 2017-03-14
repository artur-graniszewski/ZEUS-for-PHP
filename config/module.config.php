<?php

namespace Zeus;

use Zeus\Controller\ZeusController;
use Zeus\Controller\Factory\ZeusControllerFactory;
use Zeus\Kernel\IpcServer\Adapter\IpcAdapterInterface;
use Zeus\Kernel\IpcServer\Factory\IpcAdapterAbstractFactory;
use Zeus\Kernel\IpcServer\Factory\IpcServerFactory;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\Factory\PosixProcessFactory;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess;
use Zeus\Kernel\ProcessManager\Factory\ManagerFactory;
use Zeus\Kernel\ProcessManager\Factory\SchedulerFactory;
use Zeus\Kernel\ProcessManager\Factory\ProcessFactory;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\Factory\LruDisciplineFactory;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\LruDiscipline;
use Zeus\ServerService\Manager;
use Zeus\Kernel\ProcessManager\Scheduler;
use Zeus\Kernel\ProcessManager\Process;
use Zeus\ServerService\Http\Factory\RequestFactory;
use Zeus\ServerService\Shared\Factory\AbstractServerServiceFactory;
use Zeus\ServerService\Shared\Logger\IpcLoggerFactory;
use Zeus\ServerService\Shared\Logger\IpcLoggerInterface;
use Zeus\ServerService\Shared\Logger\LoggerFactory;
use Zeus\ServerService\Shared\Logger\LoggerInterface;

return $config = [
    'console' => [
        'router' => include __DIR__ . '/console.router.config.php'
    ],
    'controllers' => [
        'invokables' => [

        ],
        'factories' => [
            ZeusController::class => ZeusControllerFactory::class,
            RequestFactory::class => RequestFactory::class,
        ]
    ],
    'service_manager' => [
        'factories' => [
            IpcLoggerInterface::class => IpcLoggerFactory::class,
            IpcAdapterInterface::class => IpcServerFactory::class,
            LoggerInterface::class => LoggerFactory::class,
            Scheduler::class => SchedulerFactory::class,
            Process::class => ProcessFactory::class,
            Manager::class => ManagerFactory::class,
            PosixProcess::class => PosixProcessFactory::class,
            LruDiscipline::class => LruDisciplineFactory::class,
            //Service::class => ServiceFactory::class,
        ],
        'abstract_factories' => [
            IpcAdapterAbstractFactory::class,
            AbstractServerServiceFactory::class,
        ],
    ],
    'zeus_process_manager' => [
        'ipc_channels' => [
            'zeus_default_1' => [
                'ipc_channel_name' => 'zeus_default',
                'ipc_directory' => getcwd() . '/',
            ]
        ],
        'schedulers' => [
            'zeus_web_scheduler_1' => [
                'scheduler_name' => 'zeus_web_scheduler',
                'multiprocessing_module' => PosixProcess::class,
                'scheduler_discipline' => LruDiscipline::class,
                'max_processes' => 32,
                'max_process_tasks' => 100,
                'min_spare_processes' => 3,
                'max_spare_processes' => 5,
                'start_processes' => 8,
                'enable_process_cache' => true,
                'ipc_channel_name' => 'zeus_default'
            ]
        ],
        'services' => [
            'zf3_httpd' => [
                'auto_start' => false,
                'service_name' => 'zeus_httpd',
                'scheduler_name' => 'zeus_web_scheduler',
                'service_adapter' => \Zeus\ServerService\Http\Service::class,
                'service_settings' => [
                    'listen_port' => 7070,
                    'listen_address' => '0.0.0.0',
                    'blocked_file_types' => [
                        'php',
                        'phtml'
                    ]
                ],
                //'logger_adapter' => LoggerInterface::class // optional
            ]
        ]
    ],
];