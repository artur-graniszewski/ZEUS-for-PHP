<?php

namespace ZeusTest\Kernel\Networking;

use Zeus\Networking\SocketServer;
use Zeus\Networking\Stream\SelectionKey;
use Zeus\Networking\Stream\Selector;
use Zeus\Networking\Stream\SocketStream;

class SelectorTest extends AbstractNetworkingTest
{
    const MIN_TEST_PORT = 7777;
    const MAX_TEST_PORT = 7787;

    public function opsProvider()
    {
        return [
            [SelectionKey::OP_READ],
            [SelectionKey::OP_WRITE],
            [SelectionKey::OP_ACCEPT],
            [SelectionKey::OP_READ|SelectionKey::OP_WRITE],
            [SelectionKey::OP_ACCEPT|SelectionKey::OP_READ],
            [SelectionKey::OP_ACCEPT|SelectionKey::OP_WRITE],
        ];
    }
    /**
     * @param int $opBits
     * @dataProvider opsProvider
     */
    public function testSelectionKeysInterestOpsModifiedBySelectorWhenRegistering(int $opBits)
    {
        $selector = new Selector();
        $stream = new DummySelectableStream(null);
        $key = $stream->register($selector, $opBits);
        $this->assertEquals($opBits, $key->getInterestOps());
    }

    public function testMultiSelectOnAcceptedSockets()
    {
        $readSelector = new Selector();
        $writeSelector = new Selector();
        $fullSelector = new Selector();
        $servers = [];
        $streams = [];

        foreach (range(self::MIN_TEST_PORT, self::MAX_TEST_PORT) as $port) {
            /** @var SocketServer[] $servers */
            $servers[$port] = $this->addServer($port);
            $this->addClient($port);

            $stream = $servers[$port]->accept();
            $streams[] = $stream;
            $readSelector->register($stream, SelectionKey::OP_READ);
            $writeSelector->register($stream, SelectionKey::OP_WRITE);
            $fullSelector->register($stream, SelectionKey::OP_ALL);
        }

        $amountToRead = $readSelector->select();
        $amountToWrite = $writeSelector->select();
        $amountChanged = $fullSelector->select();

        $this->assertEquals(0, $amountToRead, "No stream should have been readable");
        $this->assertEquals(count($servers), $amountToWrite, "All streams should have been writable");
        $this->assertEquals(count($servers), $amountChanged, "All streams should have been selected");
        //$this->assertArraySubset($streams, $fullSelector->getSelectedStreams(), "All Stream objects should be returned by Selector");
        //$this->assertArraySubset($streams, $writeSelector->getSelectedStreams(), "All Stream objects should be returned by Selector");
    }

    public function testMultiSelectOnReadableSockets()
    {
        $readSelector = new Selector();
        $fullSelector = new Selector();
        $servers = [];
        $streams = [];

        foreach (range(self::MIN_TEST_PORT, self::MAX_TEST_PORT) as $port) {
            /** @var SocketServer[] $servers */
            $servers[$port] = $this->addServer($port);
            $client = $this->addClient($port);
            fputs($client, "TEST");

            $stream = $servers[$port]->accept();
            $streams[] = $stream;
            $readSelector->register($stream, SelectionKey::OP_READ);
            $fullSelector->register($stream, SelectionKey::OP_ALL);
        }

        $amountToRead = $readSelector->select();
        $amountChanged = $fullSelector->select();

        $this->assertEquals(count($servers), $amountToRead, "All streams should have been readable");
        $this->assertEquals(count($servers), $amountChanged, "All streams should have been selected");
        //$this->assertArraySubset($streams, $fullSelector->getSelectedStreams(), "All Stream objects should be returned by Selector");
        //$this->assertArraySubset($streams, $readSelector->getSelectedStreams(), "All Stream objects should be returned by Selector");
    }

    public function testSelectorTimeout()
    {
        $selector = new Selector();
        $server = $this->addServer(self::MIN_TEST_PORT);
        $this->addClient(self::MIN_TEST_PORT);
        $stream = $server->accept();
        $selector->register($stream, SelectionKey::OP_READ);

        $now = time();
        $amountToRead = $selector->select(2000);
        $this->assertEquals(0, $amountToRead, "No stream should have been readable");
        $this->assertTrue(time() <= $now + 2, "Select method should have waited at least two seconds");
        $this->assertEmpty($selector->getSelectionKeys(), "No streams should be returned");
    }

    public function testSelectorOnClosedStream()
    {
        $selector = new Selector();
        $server = $this->addServer(self::MIN_TEST_PORT);
        $this->addClient(self::MIN_TEST_PORT);
        $stream = $server->accept();
        $selector->register($stream, SelectionKey::OP_READ);
        $stream->close();

        $this->assertTrue($stream->isClosed(), "Stream should be closed");

        $amountToRead = $selector->select();
        $this->assertEquals(0, $amountToRead, "No stream should have been readable");
        $this->assertEmpty($selector->getSelectionKeys(), "No streams should be returned");
    }

    /**
     * @expectedException \TypeError
     */
    public function testOperationArgumentTypeValidation()
    {
        $selector = new Selector();
        $stream = new SocketStream(fopen(__FILE__, "r"));
        $selector->register($stream, "aa");
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Invalid operation type: -1
     */
    public function testOperationArgumentValueValidation()
    {
        $selector = new Selector();
        $stream = new SocketStream(fopen(__FILE__, "r"));
        $selector->register($stream, -1);
    }

    /**
     * @expectedException \TypeError
     * @expectedExceptionMessage Argument 1 passed to Zeus\Networking\Stream\Selector::register() must implement interface Zeus\Networking\Stream\SelectableStreamInterface
     */
    public function testStreamArgumentValidation()
    {
        $selector = new Selector();
        $stream = new \stdClass();
        $selector->register($stream, false);
    }
}