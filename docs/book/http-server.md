# Introduction

ZEUS Web Server is a simple _Server Service_ HTTP/1.1 daemon responsible for hosting _Zend Framework 3_ web applications in a multi-processing environment.

_When serving Zend Framework 3 PHP applications - it's able to outperform other, standalone servers such as Apache HTTPD or NGINX by a margin of up to 30%._

In case of acting as as server for static content such as images or binary files, ZEUS Web Server can be up to 50% slower than the Apache counterpart (but still able to handle more than **16000** static file requests per second on a 3.2Ghz _Intel Core i7_ processor).

> **Please note:** 
> ZEUS Web Service is not a fully-featured web server. In it's current state, it's meant to be used as a development aid or a simple, yet efficient intranet web service without a direct access to a public network.
>
> If required, for increased security and performance, this server can be launched behind a forward proxy such as Varnish, NGINX Proxy or Squid.

This service is not enabled by default, but can be configured to auto-start with ZEUS if needed.

# Starting the Service

Command:
```
user@host:/var/www/zf-application$ php public/index.php zeus start zeus_httpd
```

Output:
```
2017-03-15 11:56:09.004    INFO 8828 --- [           main] erverService\Shared\Logger\LoggerFactory : 
 __________            _________
 \____    /____  __ __/   _____/ PHP
   /     // __ \|  |  \_____  \
  /     /\  ___/|  |  /        \
 /_______ \___  >____/_______  /
         \/   \/             \/ 
 ZEUS for PHP - ZF3 Edition   (1.3.4)

2017-03-15 11:56:09.004    INFO 8828 --- [           main] el\ProcessManager\Factory\ManagerFactory : Scanning configuration for services...
2017-03-15 11:56:09.009    INFO 8828 --- [           main] el\ProcessManager\Factory\ManagerFactory : Found 1 service(s): zeus_httpd
2017-03-15 10:56:09.011    INFO 8828 --- [     zeus_httpd] Zeus\ServerService\Http\Service          : Launching HTTP server on 0.0.0.0:7070
2017-03-15 10:56:09.016    INFO 8828 --- [     zeus_httpd] Zeus\Kernel\ProcessManager\Scheduler     : Starting server
2017-03-15 10:56:09.020   DEBUG 8829 --- [     zeus_httpd] Zeus\Kernel\ProcessManager\Scheduler     : Scheduler starting...
2017-03-15 10:56:09.020    INFO 8828 --- [           main] Zeus\Controller\ZeusController           : Started 1 services in 0.01 seconds (PHP running for 0.09)
2017-03-15 10:56:09.020    INFO 8829 --- [     zeus_httpd] Zeus\Kernel\ProcessManager\Scheduler     : Scheduler started
```

In its default configuration, Zend Framework 3 web application can be accessed under [http://localhost:7070/](http://localhost:7070/) URL (hostname may differ if ZEUS is accessed remotely).

# Configuration

Custom HTTP server can be defined through a Zend Framework `ServiceManager` and its configuration files, like so:

```php
<?php 
// contents of "zf3-application-directory/config/some-config.config.php" file:

use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\LruDiscipline;

return [
    'zeus_process_manager' => [
        'schedulers' => [
            'custom_web_scheduler_1' => [
                'scheduler_name' => 'custom_web_scheduler',
                'multiprocessing_module' => PosixProcess::class,
                'scheduler_discipline' => LruDiscipline::class,
                'max_processes' => 32,
                'max_process_tasks' => 100,
                'min_spare_processes' => 3,
                'max_spare_processes' => 5,
                'start_processes' => 8,
                'enable_process_cache' => true,
            ]
        ],
        'services' => [
            'custom_httpd_1' => [
                'auto_start' => true,
                'service_name' => 'custom_httpd',
                'scheduler_name' => 'custom_web_scheduler',
                'service_adapter' => \Zeus\ServerService\Http\Service::class,
                'service_settings' => [
                    'listen_port' => 80,
                    'listen_address' => '0.0.0.0',
                    'blocked_file_types' => [
                        'php',
                        'phtml'
                    ]
                ],
                //'logger_adapter' => LoggerInterface::class // optional
            ]
        ],
    ]
];
```

The table below describes the `service_settings` configuration parameters:

| Parameter            | Required | Description                                                                                 |
|----------------------|:--------:|---------------------------------------------------------------------------------------------|
| `listen_port`        | yes      | The service listen port, 80 is a default HTTP port                                          |
| `listen_address`     | yes      | The service listen address, use 0.0.0.0 to listen on all available network addresses        |
| `blocked_file_types` | yes      | A blacklist of file extensions that should not be served as plain text by ZEUS Web Server   |
| `logger_adapter`     | no       | Custom logger service used for HTTP request logging (instantiated by ZF `ServiceManager` )  |

To start such a service, the following command must be issued in a terminal:

Command:
```
user@host:/var/www/zf-application$ php public/index.php zeus start custom_httpd
```
