# Athletic tests

ZEUS comes equipped with scripts for benchmarking its Memcached service using the Athletic framework; these tests can be found in the `benchmarks/Memcached` directory.

To execute the benchmarks the following command must be issued:

```
user@host:/var/www/zf-apigility-skeleton/vendor/zeus-server/zf3-server$ ../../bin/athletic -p benchmarks/Memcached
```

Output (on the _Intel Core i7_ processor):
```

ZeusBench\Memcached\MemcachedMessageBenchmark
    Method Name   Iterations    Average Time      Ops/second
    -----------  ------------  --------------    -------------
    setCommand : [5,000     ] [0.0000407902718] [24,515.64937]
    getCommand : [5,000     ] [0.0000305454254] [32,738.12646]
    incrCommand: [5,000     ] [0.0000216811657] [46,122.98130]
    decrCommand: [5,000     ] [0.0000211765766] [47,221.98579]
```