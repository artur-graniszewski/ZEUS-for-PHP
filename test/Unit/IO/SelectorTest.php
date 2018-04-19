<?php

namespace ZeusTest\Unit\IO;

use Zeus\IO\Exception\IOException;
use Zeus\IO\SocketServer;
use Zeus\IO\Stream\SelectionKey;
use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;

class SelectorTest extends AbstractIOTest
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

    /**
     * @expectedException \Zeus\IO\Exception\IOException
     * @expectedExceptionMessage Unable to register: stream is closed
     * @dataProvider opsProvider
     * @param int $opBits
     */
    public function testRegistrationOfClosedStream(int $opBits)
    {
        $selector = new Selector();
        $stream = new DummySelectableStream(null);
        $stream->setClosed(true);
        $stream->register($selector, $opBits);
    }

    /**
     * @expectedException \Zeus\IO\Exception\IOException
     * @expectedExceptionMessage Unable to register: stream is not readable
     */
    public function testRegistrationOfUnreadableStream()
    {
        $selector = new Selector();
        $stream = new DummySelectableStream(null);
        $stream->setReadable(false);
        $stream->register($selector, SelectionKey::OP_READ);
    }

    /**
     * @expectedException \Zeus\IO\Exception\IOException
     * @expectedExceptionMessage Unable to register: stream is not writable
     */
    public function testRegistrationOfNonwritableStream()
    {
        $selector = new Selector();
        $stream = new DummySelectableStream(null);
        $stream->setWritable(false);
        $stream->register($selector, SelectionKey::OP_WRITE);
    }

    public function testUnregistration()
    {
        $selector = new Selector();
        $stream = new DummySelectableStream(null);
        $key = $stream->register($selector, SelectionKey::OP_READ);
        $this->assertContains($key, $selector->getKeys());
        $selector->unregister($stream);
        $this->assertEmpty($selector->getKeys());
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
     * @expectedExceptionMessage Argument 1 passed to Zeus\IO\Stream\Selector::register() must be an instance of Zeus\IO\Stream\SelectableStreamInterface, instance of stdClass given
     */
    public function testStreamArgumentValidation()
    {
        $selector = new Selector();
        $stream = new \stdClass();
        $selector->register($stream, false);
    }
}