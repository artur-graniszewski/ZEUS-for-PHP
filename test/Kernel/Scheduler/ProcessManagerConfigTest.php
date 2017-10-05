<?php

namespace ZeusTest\Kernel\Scheduler;

use Zeus\Kernel\ProcessManager\Config;
use ZeusTest\Helpers\AbstractConfigTestHelper;

class HttpConfigTest extends AbstractConfigTestHelper
{
    protected $configClass = Config::class;

    public function configDataProvider()
    {
        return [
            [rand(1, 10000), 'start_processes', 'StartProcesses'],
            [rand(1, 10000), 'max_processes', 'MaxProcesses'],
            [rand(1, 10000), 'min_spare_processes', 'MinSpareProcesses'],
            [rand(1, 10000), 'max_spare_processes', 'MaxSpareProcesses'],
            [rand(1, 10000), 'processes_idle_timeout', 'ProcessIdleTimeout'],
            [rand(1, 10000), 'max_process_tasks', 'MaxProcessTasks'],
            [md5((string) microtime(true)), 'service_name', 'ServiceName'],
            [md5((string) microtime(true)) . '/', 'ipc_directory', 'IpcDirectory'],
            [true, 'auto_start', 'AutoStartEnabled'],
            [false, 'auto_start', 'AutoStartEnabled'],
            [true, 'enable_process_cache', 'ProcessCacheEnabled'],
            [false, 'enable_process_cache', 'ProcessCacheEnabled']
        ];
    }
}