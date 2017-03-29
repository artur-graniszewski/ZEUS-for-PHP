<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use React\EventLoop\LoopInterface;
use Zeus\ServerService\Shared\React\ReactWritableHighSpeedBuffer;
use ZeusTest\Helpers\CallableStub;

class ReactBufferTest extends PHPUnit_Framework_TestCase
{
    public function testConstructor()
    {
        $stream = fopen('php://temp', 'r+');
        $loop = $this->createLoopMock();

        $buffer = new ReactWritableHighSpeedBuffer($stream, $loop);
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->never())
            ->method('__invoke');

        $buffer->on('error', $mock);
    }

    public function testEnd()
    {
        $mockNeverCalled = $this->createCallableMock();
        $mockNeverCalled
            ->expects($this->never())
            ->method('__invoke');

        $mockCalledOnce = $this->createCallableMock();
        $mockCalledOnce
            ->expects($this->once())
            ->method('__invoke');

        $stream = fopen('php://temp', 'r+');
        $loop = $this->createLoopMock();

        $buffer = new ReactWritableHighSpeedBuffer($stream, $loop);
        $buffer->on('error', $mockNeverCalled);
        $buffer->on('close', $mockCalledOnce);

        $this->assertTrue($buffer->isWritable());
        $buffer->end();
        $this->assertFalse($buffer->isWritable());
    }

    public function testWrite()
    {
        $mockNeverCalled = $this->createCallableMock();
        $mockNeverCalled
            ->expects($this->never())
            ->method('__invoke');

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $loop = $this->createWriteableLoopMock();

        $buffer = new ReactWritableHighSpeedBuffer($sockets[0], $loop);
        $buffer->on('error', $mockNeverCalled);

        $buffer->write("test1234\n");
        $this->assertSame("test1234\n", fread($sockets[1], 1024));
    }

    public function testWriteErrorHandler()
    {
        $mockOnceCalled = $this->createCallableMock();
        $mockOnceCalled
            ->expects($this->once())
            ->method('__invoke');

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $loop = $this->createWriteableLoopMock();

        $buffer = new ReactWritableHighSpeedBuffer($sockets[0], $loop);
        $buffer->on('error', $mockOnceCalled);
        fclose($sockets[0]);
        fclose($sockets[1]);
        $buffer->write("test1234\n");
    }

    public function testWritingToClosedBufferShouldNotWriteToStream()
    {
        $stream = fopen('php://temp', 'r+');
        $loop = $this->createWriteableLoopMock();

        $buffer = new ReactWritableHighSpeedBuffer($stream, $loop);
        $buffer->close();

        $buffer->write('foo');

        rewind($stream);
        $this->assertSame('', stream_get_contents($stream));
    }

    private function createLoopMock()
    {
        $mockBuilder = $this->getMockBuilder(LoopInterface::class);
        $mockBuilder->setMethods([]);

        return $mockBuilder->getMock();
    }

    private function createCallableMock()
    {
        $mockBuilder = $this->getMockBuilder(CallableStub::class);
        $mockBuilder->setMethods([
            '__invoke',
        ]);

        return $mockBuilder->getMock();
    }

    private function createWriteableLoopMock()
    {
        $loop = $this->createLoopMock();
        $loop->preventWrites = false;
        $loop
            ->expects($this->any())
            ->method('addWriteStream')
            ->will($this->returnCallback(function ($stream, $listener) use ($loop) {
                if (!$loop->preventWrites) {
                    call_user_func($listener, $stream);
                }
            }));

        return $loop;
    }
}