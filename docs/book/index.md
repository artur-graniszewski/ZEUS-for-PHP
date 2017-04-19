[![Build Status](https://travis-ci.org/artur-graniszewski/ZEUS-for-PHP.svg?branch=master)](https://travis-ci.org/artur-graniszewski/ZEUS-for-PHP) [![Coverage Status](https://coveralls.io/repos/github/artur-graniszewski/ZEUS-for-PHP/badge.svg?branch=master)](https://coveralls.io/github/artur-graniszewski/ZEUS-for-PHP?branch=master) [![Code Climate](https://codeclimate.com/github/artur-graniszewski/ZEUS-for-PHP/badges/gpa.svg)](https://codeclimate.com/github/artur-graniszewski/ZEUS-for-PHP) [![Percentage of issues still open](http://isitmaintained.com/badge/open/artur-graniszewski/zeus-for-php.svg)](http://isitmaintained.com/project/artur-graniszewski/zeus-for-php "Percentage of issues still open")

# Introduction

![ZEUS for PHP logo](http://php.webtutor.pl/zeus/zeus-logo-small.png)

**ZEUS for PHP** is an event-driven, preemptive _Multitasking Runtime Environment_ and _Service Management System_ integrated with Zend Framework 3. It's main purpose is to perform multiple tasks (processes) concurrently.

To guarantee true parallelism and resource isolation ZEUS employs preemptive schedulers and an Inter-Process Communication mechanism to allow its processes to share the data.

***ZEUS for PHP is not a standalone service, in order to use it, it must be installed as a module on-top of any Zend Framework application!.***

It's designed to be compliant with any ZF3 application such as [Apigility](https://github.com/zfcampus/zf-apigility) or [ZendSkeletonApplication](https://github.com/zendframework/ZendSkeletonApplication). Custom applications must provide `index.php` file such as [this](https://github.com/zendframework/ZendSkeletonApplication/blob/master/public/index.php) which instantiates the Zend Framework MVC `Zend\Mvc\Application` class.

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
- Compatible with and [tested](https://travis-ci.org/artur-graniszewski/ZEUS-for-PHP) against **PHP 5.6, PHP 7.0, PHP 7.1** and **HHVM**
