<?php

namespace ZeusBench\Ipc;

use Zeus\Kernel\IpcServer\Adapter\ApcAdapter;

class ApcuIpcBenchmark extends AthleticEvent
{
    protected $ipcAdapterName = ApcAdapter::class;
}