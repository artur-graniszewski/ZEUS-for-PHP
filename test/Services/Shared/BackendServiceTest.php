<?php

namespace ZeusTest\Services\Shared;

use PHPUnit\Framework\TestCase;
use Zeus\ServerService\Shared\Networking\Service\BackendService;
use ZeusTest\Helpers\SocketTestMessage;
use ZeusTest\Helpers\SocketTestNetworkStream;

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
    }
}