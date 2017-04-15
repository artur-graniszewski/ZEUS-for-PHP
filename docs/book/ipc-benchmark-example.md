# Athletic tests

ZEUS comes equipped with scripts for benchmarking its IPC Adapters using the Athletic framework; these tests can be found in the `benchmarks/Ipc` directory.

To execute the benchmarks the following command must be issued:

```
user@host:/var/www/zf-apigility-skeleton/vendor/zeus-server/zf3-server$ ../../bin/athletic -p benchmarks/Ipc
```

Output (on the _Intel Core i7_ processor):
```

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

```