# Introduction

In ZEUS _Server Service Manager_ is a component responsible for managing and instantiating ZEUS Server Services. 

It allows to:

- view a list of installed Server Services along with their names, description and configuration
- start and stop a single Server Service
- auto-start multiple Server Services based depending on their configuration

_\* In ZEUS, Server Service is a set of one or more PHP processes running concurrently in the background. It's code must conform to the interface rules of the Server Service Manager._

To launch all `auto_start` enabled _Server Services_, the following command must be used in a user terminal:

`php public/index.php zeus start`

# Tracking Sever Service status

ZEUS for PHP is able to report current status of its Server Services to the user. It achieves this by modifying names of the active processes, which in turn, can be watched real-time in tools like `top`, `htop` or `ps`:

![ZEUS for PHP process status](http://php.webtutor.pl/zeus/zeus-console-status.png)

Also, since version 1.2.0, a new set of command line options is available:

```
user@host:/var/www/zf-application$ php public/index.php zeus status
``` 

and:

```
user@host:/var/www/zf-application$ php public/index.php zeus status <service name>
```

Which will show the user-friendly Server Service status:
![Screenshot of ZEUS Service Status in terminal](http://php.webtutor.pl/zeus/zeus-service-status.png)

# Event Manager

The Server Service Manager utilizes a `Zeus\ServerService\ManagerEvent` to handle the life-cycle of all services it handles. Since version 1.5.0, the following events are triggered:

| Constant                            | Description                                                                           |
|-------------------------------------|---------------------------------------------------------------------------------------|
| `ManagerEvent::EVENT_MANAGER_INIT`  | Triggers when Server Service Manager starts                                           |
| `ManagerEvent::EVENT_SERVICE_START` | Triggers each time the Server Service is started by a Server Service Manager          |
| `ManagerEvent::EVENT_SERVICE_STOP`  | Triggers each time the Server Service is stopped by a Server Service Manager          |

# Plugin support

Server Service Manager can be customized by using custom plugins, which can be enabled in ZEUS configuration, like so:

```php
<?php 
// contents of "zf3-application-directory/config/some-config.config.php" file:

return [
    'zeus_process_manager' => [
        'manager' => [
            'plugins' => [ // optional
                'pluginClassName', // see "ZEUS is Pluggable" documentation section for other examples of plugin definitions
            ]
        ]
    ]
];
```