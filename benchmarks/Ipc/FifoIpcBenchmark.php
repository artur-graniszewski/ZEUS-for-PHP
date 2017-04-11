<?php

namespace ZeusBench\Ipc;

use Zeus\Kernel\IpcServer\Adapter\FifoAdapter;

class FifoIpcBenchmark extends AthleticEvent
{
    protected $ipcAdapterName = FifoAdapter::class;

    public function testLargeMessage()
    {
    }
}