[![Build Status](https://travis-ci.org/artur-graniszewski/ZEUS-for-PHP.svg?branch=master)](https://travis-ci.org/artur-graniszewski/ZEUS-for-PHP) [![Coverage Status](https://coveralls.io/repos/github/artur-graniszewski/ZEUS-for-PHP/badge.svg?branch=master)](https://coveralls.io/github/artur-graniszewski/ZEUS-for-PHP?branch=master) [![Code Climate](https://codeclimate.com/github/artur-graniszewski/ZEUS-for-PHP/badges/gpa.svg)](https://codeclimate.com/github/artur-graniszewski/ZEUS-for-PHP) [![Percentage of issues still open](http://isitmaintained.com/badge/open/artur-graniszewski/zeus-for-php.svg)](http://isitmaintained.com/project/artur-graniszewski/zeus-for-php "Percentage of issues still open")

# Introduction

![ZEUS for PHP logo](http://php.webtutor.pl/zeus/zeus-logo-small.png)

**ZEUS for PHP** is an event-driven, preemptive _Multitasking Runtime Environment_ and _Service Management System_ integrated with Zend Framework 3. It's main purpose is to perform multiple tasks (processes) concurrently.

To guarantee true parallelism and resource isolation ZEUS employs preemptive schedulers and an Inter-Process Communication mechanism to allow its processes to share the data.

***ZEUS for PHP is not a standalone service, in order to use it, it must be installed as a module on-top of any Zend Framework application!.***

It's designed to be compliant with any ZF3 application such as [Apigility](https://github.com/zfcampus/zf-apigility) or [ZendSkeletonApplication](https://github.com/zendframework/ZendSkeletonApplication). Custom applications must provide `index.php` file such as [this](https://github.com/zendframework/ZendSkeletonApplication/blob/master/public/index.php) which instantiates the Zend Framework MVC `Zend\Mvc\Application` class.

![ZEUS for PHP overview](http://php.webtutor.pl/zeus/thumbnails.png)

# Features

- **Preemptive schedulers** allowing to run multiple services asynchronously
- **Built-in IPC server** with various IPC Adapters suitable for different Operating Systems or usage characteristics
- **Server service manager** allowing to start, stop and track the status of ZEUS server services
- Well defined, extensive Server Service life-cycle based on Zend Framework `EventManager` functionality and events such as `SchedulerEvent` and `ManagerEvent`
- Possibility to **write your own asynchronous Server Services** by using just few lines of boilerplate code
- Advanced status reporting tools tracking the life-cycle and usage statistics of each service and its processes
- Deep integration with Zend Framework 3+ and its services such as `EventManager` and `ServiceManager`
- Plugin support allowing to extend functionality of ZEUS Schedulers and Server Services
- **Attachable, event-driven** Server Services, Processes and Schedulers, allowing to react on and alter each step of their life-cycle
- Customizable, user friendly **Logger functionality** based on and compatible with Zend Framework 3 `Logger` module
- Easy configuration and customization of ZEUS components provided by Zend Framework 3 `Config` module
- Built-in `async()` controller plugin and the Async Server Service which allow to run multiple anonymous function/closures asynchronously
- Self hosted - ZEUS comes equipped with its own, **high-speed HTTP Server Service** implementation supporting HTTP/1.0 and HTTP/1.1 protocols, keep-alive connections and a deflate compression
- Can be integrated with any Zend Framework 3 Application with just few commands issued in a terminal
- Compatible with Zend Framework `MVC` module, enabling ZEUS to dispatch HTTP requests both for static files as well as any Zend Framework Application controller
- Equipped with its own, customizable **Memcached Server Service** that is integrated with Zend Framework 3 `Cache` module, allowing to use any of its `Cache` adapters as a Memcached key storage
- Compatible with any UNIX/Linux/BSD platform
- Well documented and [unit tested](https://travis-ci.org/artur-graniszewski/ZEUS-for-PHP) (with at least [90% code coverage](https://coveralls.io/github/artur-graniszewski/ZEUS-for-PHP))
- Stress tested, shipped with its own benchmark tests
- Compatible with and [tested](https://travis-ci.org/artur-graniszewski/ZEUS-for-PHP) against **PHP 7.0, PHP 7.1** and **HHVM**

# Documentation

**Full documentation can be [found here](http://php.webtutor.pl/zeus/docs/).**

# Sample usage

## Supported command line options

Since version 1.3.5, the following commands are supported (assuming that Zend Framework's `index.php` application bootstrap file is compatible with Zend Framework 3 `MVC` Module):

* `index.php zeus start` - Starts all ZEUS Server Services
* `index.php zeus start <service-name>` - Starts selected Server Service
* `index.php zeus list` - Lists all Server Services and their configuration
* `index.php zeus list <service-name>` - Shows the configuration of a selected Server Service
* `index.php zeus status` - Returns current status of all Server Services
* `index.php zeus status <service-name>` - Returns current status of the selected Server Service
* `index.php zeus stop` - Stops all ZEUS Server Services
* `index.php zeus stop <service-name>` - Stops selected Server Service

## Starting built-in Web Server

Command:
```
user@host:/var/www/zf-application/public$ php index.php zeus start zeus_httpd
```

Output:
```
2017-04-15 14:10:48.769    INFO 24904 --- [           main] erverService\Shared\Logger\LoggerFactory :
 __________            _________
 \____    /____  __ __/   _____/ PHP
   /     // __ \|  |  \_____  \
  /     /\  ___/|  |  /        \
 /_______ \___  >____/_______  /
         \/   \/             \/
 ZEUS for PHP - ZF3 Edition   (1.6.1)

2017-04-15 14:10:48.769    INFO 24904 --- [           main] eus\ServerService\Factory\ManagerFactory : Scanning configuration for services...
2017-04-15 14:10:48.770    INFO 24904 --- [           main] eus\ServerService\Factory\ManagerFactory : Found 4 service(s): httpd, zeus_httpd, zeus_memcache, zeus_async
2017-04-15 14:10:48.771    INFO 24904 --- [           main] Zeus\ServerService\Manager               : Starting Server Service Manager with 0 plugins
2017-04-15 14:10:48.772    INFO 24904 --- [           main] ernel\IpcServer\Factory\IpcServerFactory : Using Zeus\Kernel\IpcServer\Adapter\FifoAdapter for zeus_httpd IPC
2017-04-15 14:10:48.773    INFO 24904 --- [           main] ernel\IpcServer\Factory\IpcServerFactory : Enumerating IPC capabilities:
2017-04-15 14:10:48.773    INFO 24904 --- [           main] ernel\IpcServer\Factory\IpcServerFactory : IPC message size limit: 65536 bytes
2017-04-15 14:10:48.783    INFO 24904 --- [     zeus_httpd] rvice\Shared\AbstractSocketServerService : Launching server on 0.0.0.0:7070
2017-04-15 14:10:48.786    INFO 24904 --- [     zeus_httpd] Zeus\Kernel\ProcessManager\Scheduler     : Starting Scheduler with 1 plugin
2017-04-15 14:10:48.795    INFO 24905 --- [     zeus_httpd] Zeus\Kernel\ProcessManager\Scheduler     : Establishing IPC
2017-04-15 14:10:48.795    INFO 24905 --- [     zeus_httpd] Zeus\Kernel\ProcessManager\Scheduler     : Scheduler started
2017-04-15 14:10:48.797   DEBUG 24904 --- [           main] Zeus\ServerService\Manager               : Scheduler running as process #24905
2017-04-15 14:10:48.798    INFO 24904 --- [           main] Zeus\ServerService\Manager               : Started 1 services in 0.03 seconds (PHP running for 0.12s)

```

## Checking any Server Service status

Command:
```
user@host:/var/www/zf-application/public$ php index.php zeus status zeus_httpd
```

Output:
```
2017-04-15 14:17:44.953    INFO 28567 --- [           main] erverService\Shared\Logger\LoggerFactory :
 __________            _________
 \____    /____  __ __/   _____/ PHP
   /     // __ \|  |  \_____  \
  /     /\  ___/|  |  /        \
 /_______ \___  >____/_______  /
         \/   \/             \/
 ZEUS for PHP - ZF3 Edition   (1.6.1)

2017-04-15 14:17:44.953    INFO 28567 --- [           main] eus\ServerService\Factory\ManagerFactory : Scanning configuration for services...
2017-04-15 14:17:44.954    INFO 28567 --- [           main] eus\ServerService\Factory\ManagerFactory : Found 4 service(s): httpd, zeus_httpd, zeus_memcache, zeus_async
2017-04-15 14:17:44.960    INFO 28567 --- [           main] ernel\IpcServer\Factory\IpcServerFactory : Using Zeus\Kernel\IpcServer\Adapter\FifoAdapter for zeus_httpd IPC
2017-04-15 14:17:44.961    INFO 28567 --- [           main] ernel\IpcServer\Factory\IpcServerFactory : Enumerating IPC capabilities:
2017-04-15 14:17:44.961    INFO 28567 --- [           main] ernel\IpcServer\Factory\IpcServerFactory : IPC message size limit: 65536 bytes
2017-04-15 14:17:44.984    INFO 28567 --- [           main] Zeus\Controller\ConsoleController        : Service Status:

Service: zeus_httpd

Current time: Saturday, 15-Apr-2017 14:17:44 UTC
Restart time: Saturday, 15-Apr-2017 14:16:54 UTC
Service uptime: 50 seconds
Total tasks finished: 185957, 3.72K requests/sec
6 tasks currently being processed, 6 idle processes

E_____EERE_R....................

Scoreboard Key:
"_" Waiting for task, "R" Currently running, "E" Exiting,
"T" Terminated, "." Open slot with no current process

Service zeus_httpd
 └─┬ Scheduler 26574, CPU: 46%
   ├── Process 28510 [E] CPU: 44%, RPS: 0, REQ: 100
   ├── Process 28517 [_] CPU: 33%, RPS: 0, REQ: 78
   ├── Process 28523 [_] CPU: 52%, RPS: 0, REQ: 19
   ├── Process 28521 [_] CPU: 33%, RPS: 0, REQ: 26
   ├── Process 28524 [_] CPU: 19%, RPS: 0, REQ: 8
   ├── Process 28513 [_] CPU: 35%, RPS: 0, REQ: 96
   ├── Process 28515 [E] CPU: 33%, RPS: 0, REQ: 100
   ├── Process 28514 [E] CPU: 35%, RPS: 0, REQ: 100
   ├── Process 28519 [R] CPU: 29%, RPS: 0, REQ: 68
   ├── Process 28516 [E] CPU: 42%, RPS: 0, REQ: 100
   ├── Process 28520 [_] CPU: 42%, RPS: 0, REQ: 58
   └── Process 28522 [R] CPU: 41%, RPS: 0, REQ: 31
```

## Viewing process status using OS commands

Command:
```
user@host:/var/www/zf-application/public$ ps auxw|grep zeus|grep -v grep
```


```
osboxes   31259  0.6  2.7 639276 41972 pts/1    S+   15:43   0:00 zeus server zeus_httpd [start] 0 req done, 0 rps, 0% CPU usage
osboxes   31260 35.6  1.0 639276 16708 pts/1    S+   15:43   0:07 zeus scheduler zeus_httpd [loop] 58.78K req done, 3.97K rps, 35% CPU usage
osboxes   31862  0.0  1.1 639276 18252 pts/1    R+   15:43   0:00 zeus process zeus_httpd [waiting] 71 req done, 0 rps, 35% CPU usage
osboxes   31863  0.0  1.1 639276 18252 pts/1    R+   15:43   0:00 zeus process zeus_httpd [running] 70 req done, 0 rps, 48% CPU usage
osboxes   31864  0.0  1.1 639276 18252 pts/1    R+   15:43   0:00 zeus process zeus_httpd [waiting] 76 req done, 0 rps, 43% CPU usage
osboxes   31865  0.0  1.1 639276 18252 pts/1    R+   15:43   0:00 zeus process zeus_httpd [running] 55 req done, 0 rps, 34% CPU usage
osboxes   31866  0.0  1.1 639276 18252 pts/1    R+   15:43   0:00 zeus process zeus_httpd [waiting] 69 req done, 0 rps, 47% CPU usage
osboxes   31867  0.0  1.1 639276 18252 pts/1    R+   15:43   0:00 zeus process zeus_httpd [running] 52 req done, 0 rps, 39% CPU usage
osboxes   31868  0.0  1.1 639276 18252 pts/1    R+   15:43   0:00 zeus process zeus_httpd [running] 52 req done, 0 rps, 48% CPU usage
osboxes   31869  0.0  1.1 639276 18252 pts/1    R+   15:43   0:00 zeus process zeus_httpd [running] 61 req done, 0 rps, 51% CPU usage
osboxes   31870  0.0  1.1 639276 18252 pts/1    R+   15:43   0:00 zeus process zeus_httpd [running] 92 req done, 0 rps, 68% CPU usage
osboxes   31871  0.0  1.1 639276 18252 pts/1    R+   15:43   0:00 zeus process zeus_httpd [waiting] 12 req done, 0 rps, 37% CPU usage
```

## Executing functions asynchronously in ZF3 controllers

First, the Async Server Service must be launched in order to execute anonymous functions.

Command:
```
user@host:/var/www/zf-application/public$ php index.php zeus start zeus_async
```

The following ZF3 Application code can be handled by any HTTP Server, such as Apache HTTPD or Nginx - such functions are serialized and send to Async Server Service for asynchronous execution.

```php
<?php 
// contents of "zf3-application-directory/module/SomeModule/src/Controller/SomeController.php" file:

namespace Application\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use ZF\Apigility\Admin\Module as AdminModule;

class IndexController extends AbstractActionController
{
    public function indexAction()
    {
        for ($i = 0; $i < 12; $i++) {
            // each run() command immediately starts one task in the background and returns a handle ID
            $handles[$i] = $this->async()->run(function () use ($i) {
                sleep($i);
                return "OK $i";
            });
        }

        // join() accepts either an array of handle IDs or a single handle ID (without array)
        // - in case of array of handles, join will return an array of results,
        // - in case of a single handler, join will return a single result (not wrapped into the array)
        $results = $this->async()->join($handles);

        // because of the sleep(11) executed in a last callback, join() command will wait up to 11 seconds to fetch
        // results from all the handles, on success $result variable will contain the following data:
        // ["OK0","OK1","OK2","OK3","OK4","OK5","OK6","OK7","OK8","OK9","OK10","OK11"]

        // please keep in mind that each handle can be joined independently and join() blocks until the slowest
        // callback returns the data, therefore running $this->async()->join($handles[3]) command instead
        // would block this controller only for 3 seconds

        // usual Zend Framework stuff to return data to the view layer
        $view = new ViewModel();
        $view->setVariable('async_results', $results);
    }
}
```

## Building HTML documentation

Please note, *mkdocs* must be installed first using the `apt-get` command, or any other OS-specific package-manager.

Command:
```
user@host:/var/www/zf-application/vendor/zeus-server/zf3-server$ make doc-server
```

## Serving markdown documentation

Command:
```
user@host:/var/www/zf-application/vendor/zeus-server/zf3-server$ make doc-build
```

After executing above command, ZEUS documentation can be found under the following URL: http://127.0.0.1:8080/

# Performance

Most of the ZEUS code was heavily optimized and thoroughly tested for speed and efficiency. 

As the response times of most of ZEUS services dropped below **1 milisecond**, its common for ZEUS to handle more than **24,000 requests/second** on an average mobile Intel Core i7 processor:
```
Server Software:
Server Hostname:        127.0.0.1
Server Port:            7070

Document Path:          /apigility-ui/img/ag-hero.png
Document Length:        0 bytes

Concurrency Level:      16
Time taken for tests:   20.134 seconds
Complete requests:      500000
Failed requests:        0
Keep-Alive requests:    495061
Total transferred:      69420976 bytes
HTML transferred:       0 bytes
Requests per second:    24833.87 [#/sec] (mean)
Time per request:       0.644 [ms] (mean)
Time per request:       0.040 [ms] (mean, across all concurrent requests)
Transfer rate:          3367.17 [Kbytes/sec] received

Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:        0    0   0.0      0       2
Processing:     0    1   0.8      1      34
Waiting:        0    1   0.8      1      34
Total:          0    1   0.8      1      34

Percentage of the requests served within a certain time (ms)
  50%      1
  66%      1
  75%      1
  80%      1
  90%      1
  95%      1
  98%      2
  99%      3
 100%     34 (longest request)
```

Or achieve transfer speeds higher than **23 Gbits/sec**:
```
Server Software:
Server Hostname:        127.0.0.1
Server Port:            7070

Document Path:          /test.file.txt
Document Length:        1048576 bytes

Concurrency Level:      16
Time taken for tests:   16.564 seconds
Complete requests:      50000
Failed requests:        0
Keep-Alive requests:    49514
Total transferred:      52435892224 bytes
HTML transferred:       52428800000 bytes
Requests per second:    3018.60 [#/sec] (mean)
Time per request:       5.300 [ms] (mean)
Time per request:       0.331 [ms] (mean, across all concurrent requests)
Transfer rate:          3091460.25 [Kbytes/sec] received

Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:        0    0   0.0      0       1
Processing:     1    5  10.8      2      63
Waiting:        0    0   0.9      0      33
Total:          1    5  10.8      2      63

Percentage of the requests served within a certain time (ms)
  50%      2
  66%      2
  75%      3
  80%      3
  90%      6
  95%     40
  98%     43
  99%     44
 100%     63 (longest request)
```

# Requirements

## OS requirements
- Linux/Unix/BSD platform
- _Windows platform currently not supported_

## PHP requirements
- PHP 7.0+ or HHVM
- Posix module installed and enabled
- Pcntl module installed and enabled
- socket functions enabled for IPC purposes

## Library requirements
- Zend Framework 3+ application (with the following modules installed: `zend-mvc`, `zend-mvc-console`, `zend-console`, `zend-log`, `zend-config`)
- Opis library (`opis/closure`)

# Installation

ZEUS for PHP can be installed in two different ways:

## Downloading

### via Composer: 

```
user@host:/var/www/$ cd zf3-application-directory
user@host:/var/www/zf3-application-directory$ composer require zeus-server/zf3-server
```

### by downloading source code

Source codes can be found in ZIP file under the following URL: https://github.com/artur-graniszewski/ZEUS-for-PHP/archive/master.zip

After downloading, contents of the compressed `ZEUS-for-PHP-master` directory in ZIP file must be unpacked into a ZF3 `zf3-application-directory/module/Zeus` directory.

## Enabling ZEUS module

After installation, ZEUS for PHP must be activated in Zend Framework's `config/modules.config.php` file, like so:

```php
<?php 
// contents of "zf3-application-directory/config/modules.config.php" file:

return [
    'Zend\\Log',
    'Zend\\Mvc\\Console',
    '...',
    'Zeus' // this line should be added
];
```

This can be achieved either by modifying configuration file in any text editor, or by issuing `sed` command in Application's root directory:
```
user@host:/var/www/zf3-application-directory$ sed -i "s/'Zend\\\Log',/'Zend\\\Log','Zeus',/g" config/modules.config.php
```

If ZEUS for PHP is installed correctly, the following terminal command will show ZEUS version and its services in console:

```
user@host:/var/www/zf-application/public$ php index.php zeus status zeus_httpd
```

# Road map

## Short-term

### Documentation
- Improvement of ZEUS documentation
- Tutorials and How-to's

### Web Server
- Code refactor and HTTP performance improvements
- Performance improvements in Application dispatcher when handling Zend Framework applications
- (implemented) ~~Removing dependency on ReactPHP~~
- More configuration options

### Inter-Process Communication
- Various code improvements in IPC adapters
- Introduction of IPC strategy that will choose the most efficient IPC implementation depending on a platform.

### Server Service Manager
- Additional `EventManager` events covering full application lifecycle
- (implemented) ~~More advanced Service reporting and control tools for terminal and remote use~~
- (implemented) ~~Add a plugin that drops user privileges on start of the _Server Service_~~
- Advanced, `systemd`-like handling of Server Service failures or remote shutdowns

### Process Manager
- (implemented) ~~Configurable, more robust scheduling strategies (like terminating processes that handled the largest amount of request, etc)*~~ 

### Tests
- More automatic tests

# Long-Term

### POSIX Threads
- Adding support for threads in PHP

### Connection pooling
- NGINX like connection pooling in ZEUS Web Server (performance improvement)
- Abstract Server Service classes that will speed up the development of other types of connection pooling services
- Database connection pooling (?)

### FastCGI/FPM
- Add possibility to execute Server Services in isolated PHP instances
- Allow ZEUS Web Server to host FastCGI/FPM applications

### Experimental support for Windows platform

### Other services
- (implemented Memcached instead) ~~Redis Server Service implementation PoC ~~ 
- More features introduced to ZEUS Web Server