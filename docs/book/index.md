[![Build Status](https://travis-ci.org/artur-graniszewski/ZEUS-for-PHP.svg?branch=master)](https://travis-ci.org/artur-graniszewski/ZEUS-for-PHP) [![Coverage Status](https://coveralls.io/repos/github/artur-graniszewski/ZEUS-for-PHP/badge.svg?branch=master)](https://coveralls.io/github/artur-graniszewski/ZEUS-for-PHP?branch=master) [![Code Climate](https://codeclimate.com/github/artur-graniszewski/ZEUS-for-PHP/badges/gpa.svg)](https://codeclimate.com/github/artur-graniszewski/ZEUS-for-PHP) [![Percentage of issues still open](http://isitmaintained.com/badge/open/artur-graniszewski/zeus-for-php.svg)](http://isitmaintained.com/project/artur-graniszewski/zeus-for-php "Percentage of issues still open")

# Introduction

![ZEUS for PHP logo](http://php.webtutor.pl/zeus/zeus-logo-small.png)

**ZEUS for PHP** is an event-driven, preemptive _Multitasking Runtime Environment_ and _Service Management System_ integrated with Zend Framework 3. It's main purpose is to perform multiple tasks (processes) concurrently.

To guarantee true parallelism and resource isolation ZEUS employs preemptive schedulers and an Inter-Process Communication mechanism to allow its processes to share the data.

***ZEUS for PHP is not a standalone service, in order to use it, it must be installed as a module on-top of any Zend Framework application!.***

It's designed to be compliant with any ZF3 application such as Apigility or ZendSkeleton. Custom applications must provide index.php file which starts the Zend Framework MVC `Application` class.
