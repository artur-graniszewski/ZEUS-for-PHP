<?php

namespace Zeus;

use Zend\Mvc\Service\EventManagerFactory;
use Zeus\Controller\MainController;
use Zeus\Controller\Factory\ControllerFactory;
use Zeus\Controller\WorkerController;
use Zeus\Kernel\Scheduler\MultiProcessingModule\Factory\MultiProcessingModuleFactory;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixProcess;
use Zeus\Kernel\Scheduler\MultiProcessingModule\PosixThread;
use Zeus\Kernel\Scheduler\MultiProcessingModule\ProcessOpen;
use Zeus\Kernel\Scheduler\Plugin\SchedulerStatus;
use Zeus\ServerService\Async\AsyncPlugin;
use Zeus\ServerService\Async\Factory\AsyncPluginFactory;
use Zeus\ServerService\Factory\ManagerFactory;
use Zeus\Kernel\Scheduler\Factory\SchedulerFactory;
use Zeus\Kernel\Scheduler\Factory\WorkerFactory;
use Zeus\Kernel\Scheduler\Discipline\Factory\LruDisciplineFactory;
use Zeus\Kernel\Scheduler\Discipline\LruDiscipline;
use Zeus\Kernel\Scheduler\Plugin\ProcessTitle;
use Zeus\ServerService\Manager;
use Zeus\Kernel\Scheduler;
use Zeus\Kernel\Scheduler\Worker;
use Zeus\ServerService\Memcache\Factory\MemcacheFactory;
use Zeus\ServerService\Shared\Factory\AbstractServerServiceFactory;
use Zeus\ServerService\Shared\Logger\LoggerFactory;
use Zeus\ServerService\Shared\Logger\LoggerInterface;
use Zeus\ServerService\Memcache\Service as MemcacheService;

return $config = [
    'console' => [
        'router' => include __DIR__ . '/console.router.config.php'
    ],
    'controllers' => [
        'invokables' => [

        ],
        'factories' => [
            MainController::class => ControllerFactory::class,
            WorkerController::class => ControllerFactory::class,

        ]
    ],
    'controller_plugins' => [
        'factories' => [
            AsyncPlugin::class => AsyncPluginFactory::class,
        ],
        'aliases' => [
            'async' => AsyncPlugin::class
        ]
    ],

    'service_manager' => [
        'factories' => [
            'zeus-event-manager' => EventManagerFactory::class,
            LoggerInterface::class => LoggerFactory::class,
            Scheduler::class => SchedulerFactory::class,
            Worker::class => WorkerFactory::class,
            Manager::class => ManagerFactory::class,
            PosixThread::class => MultiProcessingModuleFactory::class,
            PosixProcess::class => MultiProcessingModuleFactory::class,
            ProcessOpen::class => MultiProcessingModuleFactory::class,
            LruDiscipline::class => LruDisciplineFactory::class,
            MemcacheService::class => MemcacheFactory::class,
        ],
        'abstract_factories' => [
            AbstractServerServiceFactory::class,
        ],
    ],
    'caches' => [
        'zeus_server_cache' => [
            'adapter' => [
                'name'    => 'apcu',
                'options' => [
                ]
            ],
        ],
        'zeus_client_cache' => [
            'adapter' => [
                'name'    => 'apcu',
                'options' => [
                ]
            ],
        ]
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
                //'multiprocessing_module' => ProcessOpen::class,
                'multiprocessing_module' => PosixProcess::class,
                //'multiprocessing_module' => PosixThread::class,
                'scheduler_discipline' => LruDiscipline::class,
                'max_processes' => 32,
                'max_process_tasks' => 100,
                'min_spare_processes' => 3,
                'max_spare_processes' => 5,
                'start_processes' => 8,
                'enable_process_cache' => true,
                'plugins' => [
                    ProcessTitle::class,
                    SchedulerStatus::class,
                    /*
                    \Zeus\Kernel\Scheduler\Plugin\DropPrivileges::class => [
                        'user' => 'www-data',
                        'group' => 'www-data',
                    ]
                    */
                ]
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
                    ],
                ],
                //'logger_adapter' => LoggerInterface::class // optional
            ],
            'zeus_memcache' => [
                'auto_start' => false,
                'service_name' => 'zeus_memcache',
                'scheduler_name' => 'zeus_web_scheduler',
                'service_adapter' => \Zeus\ServerService\Memcache\Service::class,
                'service_settings' => [
                    'listen_port' => 11211,
                    'listen_address' => '0.0.0.0',
                    'server_cache' => 'zeus_server_cache',
                    'client_cache' => 'zeus_client_cache',
                ],
            ],
            'zeus_async' => [
                'auto_start' => false,
                'service_name' => 'zeus_async',
                'scheduler_name' => 'zeus_web_scheduler',
                'service_adapter' => \Zeus\ServerService\Async\Service::class,
                'service_settings' => [
                    'listen_port' => 9999,
                    'listen_address' => '127.0.0.1',
                ],
            ]
        ]
    ],
];