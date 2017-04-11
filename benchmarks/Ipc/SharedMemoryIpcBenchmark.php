<?php

namespace ZeusBench\Ipc;

use Zeus\Kernel\IpcServer\Adapter\SharedMemoryAdapter;

class SharedMemoryIpcBenchmark extends AthleticEvent
{
    protected $ipcAdapterName = SharedMemoryAdapter::class;
}