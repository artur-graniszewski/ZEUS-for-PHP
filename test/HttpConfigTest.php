<?php

namespace ZeusTest;

use Zeus\ServerService\Http\Config;
use ZeusTest\Helpers\AbstractConfigTestHelper;

class ProcessManagerConfigTest extends AbstractConfigTestHelper
{
    protected $configClass = Config::class;

    public function configDataProvider()
    {
        return [
            [rand(1, 10000), 'listen_port', 'ListenPort'],
            [md5((string) microtime(true)), 'listen_address', 'ListenAddress'],
            [true, 'enable_keep_alive', 'KeepAliveEnabled'],
            [false, 'enable_keep_alive', 'KeepAliveEnabled'],
            [rand(1, 10000), 'keep_alive_timeout', 'KeepAliveTimeout'],
            [rand(1, 10000), 'max_keep_alive_requests_limit', 'MaxKeepAliveRequestsLimit']
        ];
    }
}