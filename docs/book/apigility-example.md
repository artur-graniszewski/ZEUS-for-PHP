# Installing Apigility

Commands:
```
user@host:/var/www$ composer create-project zfcampus/zf-apigility-skeleton --quiet
user@host:/var/www$ cd zf-apigility-skeleton/
user@host:/var/www/zf-apigility-skeleton$ composer development-enable
```

Output:
```
> zf-development-mode enable
You are now in development mode.
```

# Installing ZEUS on top of Apigility
Command:
```
user@host:/var/www/zf-apigility-skeleton$ composer require zeus-server/zf3-server
```

Output:
```
Using version ^1.3 for zeus-server/zf3-server
./composer.json has been updated
Loading composer repositories with package information
Updating dependencies (including require-dev)
  - Installing psr/log (1.0.2)
    Loading from cache

  - Installing zendframework/zend-log (2.9.1)
    Loading from cache


  Please select which config file you wish to inject 'Zend\Log' into:
  [0] Do not inject
  [1] config/modules.config.php
  [2] config/development.config.php.dist
  Make your selection (default is 0):1

  Remember this option for other packages of the same type? (y/N)y
Installing Zend\Log from package zendframework/zend-log
  - Installing evenement/evenement (v2.0.0)
    Loading from cache

  - Installing psr/http-message (1.0.1)
    Loading from cache

  - Installing ringcentral/psr7 (1.2.1)
    Loading from cache

  - Installing zendframework/zend-text (2.6.0)
    Loading from cache

  - Installing zendframework/zend-mvc-console (1.1.11)
    Loading from cache

Installing Zend\Mvc\Console from package zendframework/zend-mvc-console
  - Installing zeus-server/zf3-server (1.3.5)
    Downloading: 100%         

zendframework/zend-log suggests installing ext-mongo (mongo extension to use Mongo writer)
zendframework/zend-log suggests installing ext-mongodb (mongodb extension to use MongoDB writer)
zendframework/zend-log suggests installing zendframework/zend-mail (Zend\Mail component to use the email log writer)
Writing lock file
Generating autoload files
```

# Enabling ZEUS module in Application's config

Command:
```
user@host:/var/www/zf-apigility-skeleton$ sed -i "s/'Application',/'Application','Zeus',/g" config/modules.config.php
```

# Checking ZEUS status

Command:
```
\\
user@host:/var/www/zf-apigility-skeleton$ php public/index.php zeus status
```

Output:
```
2017-03-15 11:55:04.310    INFO 8802 --- [           main] erverService\Shared\Logger\LoggerFactory : 
 __________            _________
 \____    /____  __ __/   _____/ PHP
   /     // __ \|  |  \_____  \
  /     /\  ___/|  |  /        \
 /_______ \___  >____/_______  /
         \/   \/             \/ 
 ZEUS for PHP - ZF3 Edition   (1.3.4)

2017-03-15 11:55:04.311    INFO 8802 --- [           main] el\ProcessManager\Factory\ManagerFactory : Scanning configuration for services...
2017-03-15 11:55:04.316    INFO 8802 --- [           main] el\ProcessManager\Factory\ManagerFactory : Found 1 service(s): zeus_httpd
2017-03-15 10:55:04.326     ERR 8802 --- [           main] Zeus\Controller\ZeusController           : Service "zeus_httpd" is offline or too busy to respond

```

# Starting ZEUS Web Server
Command:
```
user@host:/var/www/zf-apigility-skeleton$ php public/index.php zeus start zeus_httpd
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

# Opening Apigility UI in a Web Browser

Open the following URL in your favourite browser: [http://localhost:7070/](http://localhost:7070/)

_Host name may differ if you're running ZEUS outside of your local machine._
