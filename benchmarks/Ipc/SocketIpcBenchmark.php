<?php

namespace ZeusBench\Ipc;

use Zeus\Kernel\IpcServer\Adapter\SocketAdapter;

class SocketIpcBenchmark extends AthleticEvent
{
    protected $ipcAdapterName = SocketAdapter::class;
}