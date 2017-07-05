# Introduction

The _Process Scheduler_ is responsible for removal of running processes* from the CPU and the selection of another processes using the Task-Pool strategy. 

It implements the concept of a _Server Service_, which is treated as a group of processes that can be managed as a whole, share same data and be placed under shared restrictions (such as timeouts, resource usage or effective user privileges). As Process Scheduler has the ability to stop and start processes - it decides how many processes are to run as part of a each Service, and the degree of concurrency to be supported at any one time.

Scheduler is responsible for:

- running _Server Services_ in parallel using __preemptive multitasking__
- supporting custom _Multi-Processing Modules_
- managing the number of processes based on a Task-Pool strategy and Scheduler Disciplines
- handling Process lifecycle
- keeping track of and reporting Process state

# Scheduler subservices

## Scheduler Disciplines

In ZEUS, scheduling disciplines are algorithms used for distributing hardware resources (such as CPU time or memory) among Scheduler processes. 

The main purpose of scheduling algorithms is to minimize resource starvation by creating or termination of processes to keep number of active processes within the boundaries specified by Scheduler configuration. 
Some algorithms may focus on termination of processes that were idle for too long, while other may terminate processes based on their actual memory footprint or a number of requests that they already processed.

> Since version 1.3.4, Schedulers can be configured to use custom `\Zeus\Kernel\ProcessManager\Scheduler\Discipline\DisciplineInterface` implementations. 
> **If no such implementation is specified, ZEUS defaults to a built-in _LRU (Least Recently Used) Discipline_.**

## Multi-Processing Modules

Certain multitasking architectures are incompatible or not efficient enough on different operating systems. To remedy this issue, ZEUS provides a Multi-Processing Module interface between an application and the underlying operating system that is designed to hide these differences by providing a consistent platform on which the application is run. 
 
MPM's primary role is to optimize ZEUS for each platform, by providing platform specific architecture implementation that might be more advantageous than others.

_ZEUS is shipped with a POSIX compliant process implementation which is well suited for most Unix/Linux operating systems._

Custom made MPMs must implement `Zeus\Kernel\ProcessManager\MultiProcessingModule\MultiProcessingModuleInterface`

# Configuration

Multiple _Process Schedulers_ can be configured in a regular Zend Framework 3 configuration file, like so:

```php
<?php 
// contents of "zf3-application-directory/config/some-config.config.php" file:

use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\LruDiscipline;

return [
    'zeus_process_manager' => [
        'schedulers' => [
            'scheduler_1' => [
                'scheduler_name' => 'sample_scheduler',
                'scheduler_discipline' => LruDiscipline::class, // choice available since version 1.3.4
                'multiprocessing_module' => PosixProcess::class,
                'max_processes' => 32,
                'max_process_tasks' => 100,
                'min_spare_processes' => 3,
                'max_spare_processes' => 5,
                'start_processes' => 8,
                'enable_process_cache' => true,
                'plugins' => [ // optional
                    'pluginClassName', // see "Plugin support" documentation section for other examples of plugin definitions
                ]
            ]
        ]
    ]
];
```

The table below describes the configuration parameters:

| Parameter                | Required | Description                                                                           |
|--------------------------|:--------:|---------------------------------------------------------------------------------------|
| `scheduler_name`         | yes      | Unique name of the scheduler configuration                                            |
| `scheduler_discipline`   | no       | Zend Framework service providing Scheduler's process management strategy              |
| `multiprocessing_module` | yes      | Specifies a `MultiProcessingModuleInterface` implementation to be used in a Scheduler |
| `start_processes`        | yes      | Specifies the number of processes that will initially launch with each Server Service |
| `max_processes`          | yes      | Maximum number of running/waiting processes of each Server Service                    |
| `max_process_tasks`      | yes      | Maximum number of tasks performed by each process before its exit                     |
| `enable_process_cache`   | yes      | Controls whether pre-forking mechanism should be used for increased performance       |
| `min_spare_processes`    | yes      | Minimal number of processes in a waiting state when process cache is enabled          |
| `max_spare_processes`    | yes      | Maximum number of waiting processes when the process cache is enabled                 |
| `plugins`                | no       | List of plugins that should be enabled for a particular scheduler                     |


# Plugin support

As of version 1.4.1 of ZEUS for PHP, Schedulers behaviour can be extended through its configuration with custom plugins implementing the `\Zend\EventManager\ListenerAggregateInterface` interface.

Custom plugins can be specified in Schedulers configuration like so (see `plugins` JSON node):

```php
<?php 
// contents of "zf3-application-directory/config/some-config.config.php" file:

use Zeus\Kernel\ProcessManager\MultiProcessingModule\PosixProcess;
use Zeus\Kernel\ProcessManager\Scheduler\Discipline\LruDiscipline;

return [
    'zeus_process_manager' => [
        'schedulers' => [
            'scheduler_1' => [
                'scheduler_name' => 'sample_scheduler',
                'scheduler_discipline' => LruDiscipline::class,
                'multiprocessing_module' => PosixProcess::class,
                'max_processes' => 32,
                'max_process_tasks' => 100,
                'min_spare_processes' => 3,
                'max_spare_processes' => 5,
                'start_processes' => 8,
                'enable_process_cache' => true,
                'plugins' => [
                    \Zeus\Kernel\ProcessManager\Plugin\ProcessTitle::class, // by class name
                    
                    'SomeZFServiceManagerServiceName', // by name of ZF Service instantiated by its ServiceManager,
                    
                    \Zeus\Kernel\ProcessManager\Plugin\DropPrivileges::class => [ // by class name in array key and constructor params in array value
                        'user' => 'www-data',
                        'group' => 'www-data',
                    ], 
                    
                    $someObject // directly as an object instance
                ]
            ]
        ]
    ]
];
```

Out of the box, ZEUS comes equipped with two Scheduler plugins: `\Zeus\Kernel\ProcessManager\Plugin\ProcessTitle` and `\Zeus\Kernel\ProcessManager\Plugin\DropPrivileges`.

## ProcessTitle
This plugin does not take any options, its main purpose is to alter process names in terminal to show current operations performed by ZEUS.

## DropPrivileges
This plugin requires two options to be set (user and group, see example above). Its main purpose is to switch real user of the process from root to an unprivileged account (for security purposes).
This is especially handy when running ZEUS services with root privileges using `sudo` command so they can listen on the root-restricted ports (in range of 0-1024).
