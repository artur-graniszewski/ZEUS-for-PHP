<?php

namespace ZeusTest\Services\Shared;

use Zeus\ServerService\Shared\Networking\Service\BackendService;
use ZeusTest\Helpers\SocketTestMessage;
use ZeusTest\Helpers\SocketTestNetworkStream;

/**
 * Class BackendServiceTest
 * @package ZeusTest\Services\Shared
 * @runTestsInSeparateProcesses true
 * @preserveGlobalState disabled
 */
class BackendServiceTest extends AbstractServiceTest
{
    public function testServerAccept()
    {
        $clientStream = new SocketTestNetworkStream(null);
        $serverSocket = new SocketTestNetworkStream(null);
        $selector = $this->getSelectorStub(1);
        $backend = new BackendService(new SocketTestMessage());
        $backend->setServer($this->getServerStub($clientStream, $serverSocket));
        $backend->setSelector($selector);
        $backend->checkMessages();
        $this->assertTrue($backend->isClientConnected());
    }
}