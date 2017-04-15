# Prerequisites

The tests below assume that ZEUS is installed on top of the Apigility application (see "Integration with Apigility" section for details).

Also, make sure that Apache Benchmark tool is installed. 

# Static files test

## Throughput test

Open another terminal instance (ZEUS should be running in the first terminal)

We will test performance of 16 concurrent OPTIONS requests (the `-c 16 -i` switches) using the keep-alive connections (`-k` switch).

Command:
```
user@host:/var/www/zf-apigility-skeleton$ ab -n 50000 -c 16 -k -i http://127.0.0.1:7070/apigility-ui/img/ag-hero.png
```

Output (on the _Intel Core i7_ processor):
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

Concurrency Level:      16
Time taken for tests:   2.790 seconds
Complete requests:      50000
Failed requests:        0
Keep-Alive requests:    49513
Total transferred:      6942208 bytes
HTML transferred:       0 bytes
Requests per second:    17918.78 [#/sec] (mean)
Time per request:       0.893 [ms] (mean)
Time per request:       0.056 [ms] (mean, across all concurrent requests)
Transfer rate:          2429.61 [Kbytes/sec] received

Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:        0    0   0.0      0       1
Processing:     0    1   1.1      1      33
Waiting:        0    1   1.1      1      33
Total:          0    1   1.1      1      33

Percentage of the requests served within a certain time (ms)
  50%      1
  66%      1
  75%      1
  80%      1
  90%      1
  95%      2
  98%      3
  99%      5
 100%     33 (longest request)
```

In this test, ZEUS Web Server served over **17,918** requests per second.

## Bandwidth test

In this scenario we will test the performance of 16 concurrent GET requests (the `-c 16` switches) using the keep-alive connections (`-k` switch) on a 19KB static file.

Command:
```
user@host:/var/www/zf-apigility-skeleton$ ab -n 50000 -c 16 -k http://127.0.0.1:7070/apigility-ui/img/ag-hero.png
```

Output (on the _Intel Core i7_ processor with a _7200RPM HDD_):
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
Document Length:        19869 bytes

Concurrency Level:      16
Time taken for tests:   3.465 seconds
Complete requests:      50000
Failed requests:        0
Keep-Alive requests:    49514
Total transferred:      1000392224 bytes
HTML transferred:       993450000 bytes
Requests per second:    14428.10 [#/sec] (mean)
Time per request:       1.109 [ms] (mean)
Time per request:       0.069 [ms] (mean, across all concurrent requests)
Transfer rate:          281909.42 [Kbytes/sec] received

Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:        0    0   0.0      0       1
Processing:     0    1   1.2      1      28
Waiting:        0    1   1.2      1      28
Total:          0    1   1.2      1      28

Percentage of the requests served within a certain time (ms)
  50%      1
  66%      1
  75%      1
  80%      1
  90%      2
  95%      3
  98%      4
  99%      6
 100%     28 (longest request)
```

In this test setup, ZEUS Web Server achieved the speed of around **280** megabytes, or **2202** megabits (2 Gb) per second.

# Athletic tests

ZEUS comes equipped with scripts for benchmarking its HTTP Service using the Athletic framework; these tests can be found in the `benchmarks/Http` directory.

To execute the benchmarks the following command must be issued:

```
user@host:/var/www/zf-apigility-skeleton/vendor/zeus-server/zf3-server$ ../../bin/athletic -p benchmarks/Http
```

Output (on the _Intel Core i7_ processor):
```
ZeusBench\Http\HttpMessageBenchmark
    Method Name                    Iterations    Average Time      Ops/second
    ----------------------------  ------------  --------------    -------------
    getLargeRequest             : [5,000     ] [0.0001098786354] [9,100.95030]
    getMediumRequest            : [5,000     ] [0.0000641846657] [15,580.04532]
    getSmallRequest             : [5,000     ] [0.0000386142731] [25,897.15979]
    getDeflatedLargeRequest     : [5,000     ] [0.0002602560997] [3,842.36912]
    getDeflatedMediumRequest    : [5,000     ] [0.0000912987709] [10,953.04997]
    getDeflatedSmallRequest     : [5,000     ] [0.0000598844528] [16,698.82504]
    optionsLargeRequest         : [5,000     ] [0.0002242991447] [4,458.33176]
    optionsMediumRequest        : [5,000     ] [0.0001087976933] [9,191.37134]
    optionsSmallRequest         : [5,000     ] [0.0000359425068] [27,822.21078]
    optionsDeflatedLargeRequest : [5,000     ] [0.0000737112522] [13,566.44976]
    optionsDeflatedMediumRequest: [5,000     ] [0.0000518120289] [19,300.53738]
    optionsDeflatedSmallRequest : [5,000     ] [0.0000584757328] [17,101.11104]
```