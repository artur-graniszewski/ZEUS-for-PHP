# Zend Framework 3 integration

ZEUS for PHP is highly integrated with _Zend Framework 3_ (ZF3) services such as `ServiceManager` and `EventManager`. Thus, most of ZEUS components and classes are declared as ZF3 services and service factories.
 
Any custom integration with ZEUS should therefore be performed through ZF3 Service Manager and its configuration.

# ZEUS layers

ZEUS Runtime Environment consists of two layers: user and kernel:

## Kernel Mode

This is a low level set of features needed to implement any multi-tasking service.

Kernel consists of the following components: Process Scheduler and its Processes.

## User Mode

This is a higher-level ZEUS layer which provides specific services implementation by using the low level Kernel functionality.

It contains the following components: Event logger, Server Service Manager and its Server Services.

# Event driven lifecycle

ZEUS for PHP incorporates and utilizes a custom Zend event implementation - `Zeus\Kernel\ProcessManager\SchedulerEvent` throughout its entire life-cycle process.

Access to this event is public and can be used to fully customize ZEUS for PHP behaviour.

_By default, the following ZEUS event flow is in effect (events are colored yellow and light-blue):_

![ZEUS for PHP lifecycle](http://php.webtutor.pl/zeus/zeus-events-flow.png)

Both Scheduler and Process instances are isolated from each other and can share their data only through the _Inter-Process Communication_ messages. 
The same applies to ZEUS events: Scheduler events (yellow color) can't be intercepted by the Process instances, as well as Process events (light-blue) by a Scheduler instance.
