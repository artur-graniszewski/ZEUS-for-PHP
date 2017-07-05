# Creating a custom Server Service

Every _Server Service_ needs to implement `Zeus\ServerService\ServiceInterface`. 

To speed up the development process, ZEUS is shipped with the following classes and factories that can be used as a base for new services:

- `Zeus\ServerService\Shared\AbstractServerService` - a very crude boilerplate for new _Server Services_
- `Zeus\ServerService\Shared\Factory\AbstractServerServiceFactory` - an abstract Zend Framework factory that creates _Server Services_ if they don't use their own factories. 

Each service must be listed in the `services` section of `zeus_process_manager` configuration entry and point to a valid _Scheduler_ configuration (or specify its own implementation in ZEUS `schedulers` section), like so:
 
```php
<?php 
// contents of "zf3-application-directory/config/some-config.config.php" file:

use Zeus\ServerService\Shared\Logger\LoggerInterface;
use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess;

return [
    'zeus_process_manager' => [
        'services' => [
            'service_name_1' => [
                'auto_start' => false,
                'service_name' => 'some_service_name',
                'scheduler_name' => 'sample_scheduler',
                'service_adapter' => 'CustomZendFrameworkServiceName',
                'logger_adapter' => LoggerInterface::class,
                'service_settings' => [
                    'service_custom_data' => 'value'
                ]
            ]
        ],
        'schedulers' => [
            'scheduler_1' => [
                'scheduler_name' => 'sample_scheduler',
                'multiprocessing_module' => PosixProcess::class,
                'max_processes' => 32,
                'max_process_tasks' => 100,
                'min_spare_processes' => 3,
                'max_spare_processes' => 5,
                'start_processes' => 8,
                'enable_process_cache' => true
            ]
        ]
    ]
];
```

The above configuration parameters have been described in the __Process Scheduler__ and __Server Service Manager__ documentation chapters.