<?php

namespace ZeusTest\Services\Shared;

use Zeus\IO\Stream\SelectionKey;
use Zeus\ServerService\Shared\Networking\Service\GatewayService;
use Zeus\ServerService\Shared\Networking\Service\RegistratorService;
use Zeus\ServerService\Shared\Networking\Service\WorkerIPC;
use ZeusTest\Helpers\SocketTestNetworkStream;

class GatewayServiceTest extends AbstractServiceTest
{
    protected function getRegistratorStub(WorkerIPC $workerIPC = null) : RegistratorService
    {
        $mockBuilder = $this->getMockBuilder(RegistratorService::class);
        $mockBuilder->setMethods([
            'notifyRegistrator',
            'getBackendIPC',
        ]);

        /** @var RegistratorService|\PHPUnit_Framework_MockObject_MockObject $registrator */
        $registrator = $mockBuilder->getMock();
        if (!$workerIPC) {
            return $registrator;
        }

        $registrator->expects($this->atLeastOnce())
            ->method('getBackendIPC')
            ->willReturn($workerIPC);

        return $registrator;
    }

    public function testServiceStart()
    {
        $gateway = new GatewayService($this->getRegistratorStub());
        $gateway->startService('tcp://127.0.0.1', 1, 0);
        $address = $gateway->getServer()->getLocalAddress();
        $wasClosed = $gateway->getServer()->isClosed();
        $gateway->stopService();

        $this->assertStringStartsWith('tcp://127.0.0.1:', $address);
        $this->assertTrue($gateway->getServer()->isClosed());
        $this->assertFalse($wasClosed);
    }

    public function testConnectionForward()
    {
        $testMessage = "test message";
        $backendStream = new SocketTestNetworkStream(null);
        $acceptStream = new SocketTestNetworkStream(null);
        $acceptStream->setDataReceived($testMessage);

        $workerIPC = new WorkerIPC(1, 'tcp://localhost:0');
        $workerIPC->setStream($backendStream);

        $serverSocket = new SocketTestNetworkStream(null);
        $backend = new GatewayService($this->getRegistratorStub($workerIPC));
        $backend->setServer($this->getServerStub($acceptStream, $serverSocket));
        $selector = $this->getSelectorStub(1);
        $backend->setSelector($selector);

        $acceptKey = new SelectionKey($serverSocket, $selector);
        $acceptKey->setAcceptable(true);

        $clientKey = new SelectionKey($acceptStream, $selector);
        $clientKey->setReadable(true);

        /** @var \PHPUnit_Framework_MockObject_MockObject $selector */
        $selector->expects($this->at(1))
            ->method('getSelectionKeys')
            ->willReturn([$acceptKey]);

        $backend->selectStreams();
        $acceptStream->getLastSelectionKey()->setReadable(true);
        $selector->expects($this->at(1))
            ->method('getSelectionKeys')
            ->willReturn([$acceptStream->getLastSelectionKey()]);

        $backend->selectStreams();
        $this->assertEquals($testMessage, $backendStream->getSentData());

//        $acceptStream->getLastSelectionKey()->setReadable(true);
//        $selector->expects($this->at(1))
//            ->method('getSelectionKeys')
//            ->willReturn([$acceptStream->getLastSelectionKey()]);
//
//        $backend->selectStreams();
    }
}