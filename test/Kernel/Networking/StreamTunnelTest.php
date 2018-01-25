<?php

namespace ZeusTest\Kernel\Networking;

use Zeus\Networking\SocketServer;
use Zeus\Networking\Stream\FileStream;
use Zeus\Networking\Stream\SelectionKey;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;
use Zeus\ServerService\Shared\Networking\Service\StreamTunnel;

class StreamTunnelTest extends AbstractNetworkingTest
{
    private function getTunnel() : StreamTunnel
    {
        $selector = new Selector();
        $stream1 = new FileStream(fopen(__FILE__, 'r'));
        $stream2 = new FileStream(fopen(__FILE__, 'r'));
        $key1 = $stream1->register($selector, Selector::OP_READ);
        $key2 = $stream2->register($selector, Selector::OP_READ);
        $tunnel = new StreamTunnel($key1, $key2);

        return $tunnel;
    }
    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Tunnel ID is not set
     */
    public function testGetNullId()
    {
        $tunnel = $this->getTunnel();
        $tunnel->getId();
    }

    public function testGetSetId()
    {
        $selector = new Selector();
        $stream1 = new FileStream(fopen(__FILE__, 'r'));
        $stream2 = new FileStream(fopen(__FILE__, 'r'));
        $key1 = $stream1->register($selector, Selector::OP_READ);
        $key2 = $stream2->register($selector, Selector::OP_READ);
        $tunnel = new StreamTunnel($key1, $key2);
        $tunnel->setId(12);
        $this->assertEquals(12, $tunnel->getId(), "Getter should return ID set by setter");
    }

    public function testBasicReadWrite()
    {
        $selector = new Selector();
        $srcStream = new DummySelectableStream(null);
        $dstStream = new DummySelectableStream(null);
        $srcStream->setReadable(true);
        $dstStream->setWritable(true);
        $srcKey = $srcStream->register($selector, Selector::OP_READ);
        $dstKey = $dstStream->register($selector, Selector::OP_WRITE);
        $srcKey->setReadable(true);
        $dstKey->setWritable(true);
        $tunnel = new StreamTunnel($srcKey, $dstKey);

        $srcStream->setDataToRead("test1");
        $tunnel->tunnel();
        $this->assertEquals("test1", $dstKey->getStream()->getWrittenData());
    }
}