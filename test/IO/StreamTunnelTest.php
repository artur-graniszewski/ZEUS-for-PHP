<?php

namespace ZeusTest\IO;

use Zeus\IO\Stream\FileStream;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\ServerService\Shared\Networking\Service\StreamTunnel;

class StreamTunnelTest extends AbstractIOTest
{
    private function getTunnel() : StreamTunnel
    {
        $selector = new Selector();
        $stream1 = new FileStream(fopen(__FILE__, 'r'));
        $stream2 = new FileStream(fopen(__FILE__, 'r'));
        $key1 = $stream1->register($selector, SelectionKey::OP_READ);
        $key2 = $stream2->register($selector, SelectionKey::OP_READ);
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
        $key1 = $stream1->register($selector, SelectionKey::OP_READ);
        $key2 = $stream2->register($selector, SelectionKey::OP_READ);
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
        $srcKey = $srcStream->register($selector, SelectionKey::OP_READ);
        $dstKey = $dstStream->register($selector, SelectionKey::OP_WRITE);
        $srcKey->setReadable(true);
        $dstKey->setWritable(true);
        $tunnel = new StreamTunnel($srcKey, $dstKey);

        $srcStream->setDataToRead("test1");
        $tunnel->tunnel();
        $this->assertEquals($srcStream, $srcKey->getStream(), "Source streams should match");
        $this->assertEquals($dstStream, $dstKey->getStream(), "Destrination streams should match");
        $this->assertEquals("test1", $dstStream->getWrittenData());
    }
}