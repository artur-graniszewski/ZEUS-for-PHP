# Introduction

ZEUS Logger provides a set of components able to record either events that occur in its core services, or messages between different processes.

By default ZEUS log entries are written to `STDOUT` (standard output stream) and therefore, visible as a user-friendly text in the user terminal.

![Screenshot of ZEUS from a user terminal](http://php.webtutor.pl/zeus/zeus-console-log.png)

Custom loggers and output streams can be specified in ZEUS configuration.

# Configuration

Different output streams, as well as custom `Logger` adapters can be provided through a Zend Framework `ServiceManager` and its configuration files, like so:

```php
<?php 
// contents of "zf3-application-directory/config/some-config.config.php" file:

return [
    'zeus_process_manager' => [
        'logger' => [
            'reporting_level' => \Zend\Log\Logger::DEBUG,
            'output' => 'php://stdout',
            'show_banner' => true,
            'logger_adapter' => 'CustomLoggerServiceName'
        ]
    ]
];
```

The table below describes the configuration parameters:

| Parameter          | Required | Description                                                                                 |
|--------------------|:--------:|---------------------------------------------------------------------------------------------|
| `reporting_level`  | no       | Minimum severity required to log the event (see `Zend\Log\Logger::*`, default: `DEBUG`)     |
| `output`           | no       | Specifies where to write the logs, used only by default ZEUS logger, default: `STDOUT`      |
| `show_banner`      | no       | Controls whether default ZEUS logger should render its banner on startup or not             |
| `logger_adapter`   | no       | Allows to use a custom `Zend\Log\LoggerInterface` implementation for service logging        |