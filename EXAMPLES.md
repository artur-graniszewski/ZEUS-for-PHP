# Installing Apigility with ZEUS

## Installing Apigility first
Commands:
```
artur@osboxes:/var/www$ composer create-project zfcampus/zf-apigility-skeleton --quiet
artur@osboxes:/var/www$ cd zf-apigility-skeleton/
artur@osboxes:/var/www/zf-apigility-skeleton$ composer development-enable
```

Output:
```
> zf-development-mode enable
You are now in development mode.
```

## Installing ZEUS on top of Apigility
Command:
```
artur@osboxes:/var/www/zf-apigility-skeleton$ composer require zeus-server/zf3-server
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

  - Installing react/stream (v0.4.6)
    Loading from cache

  - Installing react/promise (v2.5.0)
    Loading from cache

  - Installing react/event-loop (v0.4.2)
    Loading from cache

  - Installing react/socket (v0.4.6)
    Loading from cache

  - Installing react/promise-timer (v1.1.1)
    Downloading: 100%         

  - Installing react/cache (v0.4.1)
    Loading from cache

  - Installing react/dns (v0.4.6)
    Downloading: 100%         

  - Installing react/socket-client (v0.4.6)
    Loading from cache

  - Installing psr/http-message (1.0.1)
    Loading from cache

  - Installing ringcentral/psr7 (1.2.1)
    Loading from cache

  - Installing react/http (v0.4.4)
    Loading from cache

  - Installing guzzlehttp/psr7 (1.4.1)
    Downloading: 100%         

  - Installing react/http-client (v0.4.16)
    Downloading: 100%         

  - Installing react/child-process (v0.4.3)
    Downloading: 100%         

  - Installing react/react (v0.4.2)
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
react/event-loop suggests installing ext-libevent (>=0.1.0)
react/event-loop suggests installing ext-event (~1.0)
react/event-loop suggests installing ext-libev (*)
react/react suggests installing ext-libevent (Allows for use of a more performant event-loop implementation.)
react/react suggests installing ext-libev (Allows for use of a more performant event-loop implementation.)
react/react suggests installing ext-event (Allows for use of a more performant event-loop implementation.)
Writing lock file
Generating autoload files
```

## Enabling ZEUS and checking if it responds
Commands:
```
artur@osboxes:/var/www/zf-apigility-skeleton$ sed -i "s/'Application',/'Application','Zeus',/g" config/modules.config.php
artur@osboxes:/var/www/zf-apigility-skeleton$ php public/index.php zeus status
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

## Starting ZEUS Web Server
Command:
```
artur@osboxes:/var/www/zf-apigility-skeleton$ php public/index.php zeus start zeus_httpd
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

## Opening Apigility UI in Web Browser

Open the following URL in your favourite browser: [http://localhost:7070/](http://localhost:7070/)

_Host name may differ if you're running ZEUS outside of your local machine._

## Verifying ZEUS performance

### Static files

Make sure that Apache Benchmark tool is installed.

Open another terminal instance (ZEUS should be running in the first terminal)

We will test performance of 8 concurrent OPTIONS requests (the -i switch) using the keep-alive connections.

Command:
```
artur@osboxes:/var/www/zf-apigility-skeleton$ ab -n 50000 -c 8 -k -i http://127.0.0.1:7070/apigility-ui/img/ag-hero.png
```

Output (on the _Intel Core i3_ processor):
```
This is ApacheBench, Version 2.3 <$Revision: 1706008 $>
Copyright 1996 Adam Twiss, Zeus Technology Ltd, http://www.zeustech.net/
Licensed to The Apache Software Foundation, http://www.apache.org/

Benchmarking 127.0.0.1 (be patient)
Completed 5000 requests
Completed 10000 requests
Completed 15000 requests
Completed 20000 requests
Completed 25000 requests
Completed 30000 requests
Completed 35000 requests
Completed 40000 requests
Completed 45000 requests
Completed 50000 requests
Finished 50000 requests


Server Software:        
Server Hostname:        127.0.0.1
Server Port:            7070

Document Path:          /apigility-ui/img/ag-hero.png
Document Length:        0 bytes

Concurrency Level:      8
Time taken for tests:   14.131 seconds
Complete requests:      50000
Failed requests:        0
Keep-Alive requests:    49509
Total transferred:      6942144 bytes
HTML transferred:       0 bytes
Requests per second:    3538.23 [#/sec] (mean)
Time per request:       2.261 [ms] (mean)
Time per request:       0.283 [ms] (mean, across all concurrent requests)
Transfer rate:          479.74 [Kbytes/sec] received

Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:        0    0   0.0      0       2
Processing:     0    2   3.1      1      77
Waiting:        0    2   3.0      1      77
Total:          0    2   3.1      1      77

Percentage of the requests served within a certain time (ms)
  50%      1
  66%      2
  75%      3
  80%      3
  90%      6
  95%      9
  98%     12
  99%     14
 100%     77 (longest request)

```

Similar tests may be performed for other files or Zend Framework actions.

# Running Server Service benchmarks

ZEUS comes equipped with scripts for benchmarking its services using the Athletic framework; these tests can be found in the `benchmarks/` directory.

To execute the benchmarks the following command must be issued:

```
artur@osboxes:/var/www/zf-apigility-skeleton/vendor/zeus-server/zf3-server$ ../../bin/athletic -p benchmarks
```

Output (on the _Intel Core i7_ processor):
```

ZeusBench\HttpMessageBenchmark
    Method Name                    Iterations    Average Time      Ops/second
    ----------------------------  ------------  --------------    -------------
    getLargeRequest             : [5,000     ] [0.0000876446247] [11,409.71284]
    getMediumRequest            : [5,000     ] [0.0000542313576] [18,439.51626]
    getSmallRequest             : [5,000     ] [0.0000369216919] [27,084.34930]
    getDeflatedLargeRequest     : [5,000     ] [0.0015375246048] [650.39610]
    getDeflatedMediumRequest    : [5,000     ] [0.0005287565708] [1,891.22945]
    getDeflatedSmallRequest     : [5,000     ] [0.0000904949665] [11,050.33836]
    optionsLargeRequest         : [5,000     ] [0.0001906073570] [5,246.38721]
    optionsMediumRequest        : [5,000     ] [0.0000480278969] [20,821.23234]
    optionsSmallRequest         : [5,000     ] [0.0000361358643] [27,673.33840]
    optionsDeflatedLargeRequest : [5,000     ] [0.0015187056065] [658.45546]
    optionsDeflatedMediumRequest: [5,000     ] [0.0004220429897] [2,369.42687]
    optionsDeflatedSmallRequest : [5,000     ] [0.0000562005043] [17,793.43464]


ZeusBench\Ipc\ApcuIpcBenchmark
    Method Name         Iterations    Average Time      Ops/second
    -----------------  ------------  --------------    -------------
    testSmallMessage : [10,000    ] [0.0000030758858] [325,109.60221]
    testMediumMessage: [10,000    ] [0.0000050517321] [197,951.90787]
    testLargeMessage : [10,000    ] [0.0000070984125] [140,876.56896]


ZeusBench\Ipc\FifoIpcBenchmark
    Method Name         Iterations    Average Time      Ops/second
    -----------------  ------------  --------------    -------------
    testSmallMessage : [10,000    ] [0.0000049222708] [203,158.26693]
    testMediumMessage: [10,000    ] [0.0000193856239] [51,584.61773]


ZeusBench\Ipc\MsgIpcBenchmark
    Method Name        Iterations    Average Time      Ops/second
    ----------------  ------------  --------------    -------------
    testSmallMessage: [10,000    ] [0.0000037575006] [266,134.35194]


ZeusBench\Ipc\SharedMemoryIpcBenchmark
    Method Name         Iterations    Average Time      Ops/second
    -----------------  ------------  --------------    -------------
    testSmallMessage : [10,000    ] [0.0000036380529] [274,872.30571]
    testMediumMessage: [10,000    ] [0.0000084872961] [117,823.15448]
    testLargeMessage : [10,000    ] [0.0000139183760] [71,847.46282]


ZeusBench\Ipc\SocketIpcBenchmark
    Method Name         Iterations    Average Time      Ops/second
    -----------------  ------------  --------------    -------------
    testSmallMessage : [10,000    ] [0.0000045817614] [218,256.67498]
    testMediumMessage: [10,000    ] [0.0000142321825] [70,263.29235]
    testLargeMessage : [10,000    ] [0.0000252086639] [39,668.90123]


ZeusBench\MemcachedMessageBenchmark
    Method Name   Iterations    Average Time      Ops/second
    -----------  ------------  --------------    -------------
    setCommand : [5,000     ] [0.0000400159359] [24,990.04403]
    getCommand : [5,000     ] [0.0000306358337] [32,641.51413]
    incrCommand: [5,000     ] [0.0000211413860] [47,300.58845]
    decrCommand: [5,000     ] [0.0000209878922] [47,646.51890]

```