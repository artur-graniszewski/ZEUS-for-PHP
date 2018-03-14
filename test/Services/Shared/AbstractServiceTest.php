<?php

namespace ZeusTest\Services\Shared;

use PHPUnit\Framework\TestCase;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\IO\Stream\Selector;

abstract class AbstractServiceTest extends TestCase
{
    protected function getServerStub(NetworkStreamInterface $acceptedStream, NetworkStreamInterface $serverSocket) : SocketServer
    {
        $mockBuilder = $this->getMockBuilder(SocketServer::class);
        $mockBuilder->setMethods([
            'accept',
            'getSocket',
        ]);

        /** @var SocketServer|\PHPUnit_Framework_MockObject_MockObject $server */
        $server = $mockBuilder->getMock();
        $server->expects($this->any())
            ->method('accept')
            ->willReturn($acceptedStream);

        $server->expects($this->any())
            ->method('getSocket')
            ->willReturn($serverSocket);
        return $server;
    }

    protected function getSelectorStub(int $numberOfSelectedStreams) : Selector
    {
        $mockBuilder = $this->getMockBuilder(Selector::class);
        $mockBuilder->setMethods([
            'select',
            'getSelectionKeys',
        ]);

        /** @var Selector|\PHPUnit_Framework_MockObject_MockObject $selector */
        $selector = $mockBuilder->getMock();
        $selector->expects($this->any())
            ->method('select')
            ->willReturn($numberOfSelectedStreams);

        return $selector;
    }
}