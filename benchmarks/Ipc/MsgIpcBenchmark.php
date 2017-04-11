<?php

namespace ZeusBench\Ipc;

use Zeus\Kernel\IpcServer\Adapter\MsgAdapter;

class MsgIpcBenchmark extends AthleticEvent
{
    protected $ipcAdapterName = MsgAdapter::class;

    public function testMediumMessage()
    {
    }

    public function testLargeMessage()
    {
    }
}