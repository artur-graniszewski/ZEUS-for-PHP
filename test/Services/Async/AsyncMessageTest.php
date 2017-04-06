<?php

namespace ZeusTest\Services\Async;

use PHPUnit_Framework_TestCase;
use Zeus\Module;
use Zeus\ServerService\Async\Message\Message;
use Zeus\ServerService\Shared\React\ConnectionInterface;
use ZeusTest\Helpers\TestConnection;

class AsyncMessageTest extends PHPUnit_Framework_TestCase
{
    /** @var ConnectionInterface */
    protected $connection;

    /** @var Message */
    protected $async;

    public function setUp()
    {
        $this->connection = new TestConnection();
        $this->async = new Message();
        $this->async->onOpen($this->connection);
    }

    public function testConnectionClosedOnError()
    {
        $result = $this->send("test\n", true);
        $this->assertEquals("BAD_REQUEST\n", $result);

        $this->assertTrue($this->connection->isConnectionClosed(), "Connection should be closed on error");
    }

    public function testErrorOnBadRequest()
    {
        $result = $this->send("test:aaa\n", true);
        $this->assertEquals("BAD_REQUEST\n", $result);

        $this->assertTrue($this->connection->isConnectionClosed(), "Connection should be closed on error");
    }

    public function testErrorOnCorruptedRequest()
    {
        $result = $this->send("3:aaaa\n", true);
        $this->assertEquals("CORRUPTED_REQUEST\n", $result);

        $this->assertTrue($this->connection->isConnectionClosed(), "Connection should be closed on error");
    }

    public function testStartsProcessing()
    {
        $result = $this->send("3:aaa\n", true);
        $this->assertEquals("PROCESSING\n", $result);

        $this->assertFalse($this->connection->isConnectionClosed(), "Connection should be open if no error");
    }

    public function testUnSerializationFailure()
    {
        $result = $this->send("3:aaa\n", true);
        $this->assertEquals("PROCESSING\n", $result);

        $this->assertFalse($this->connection->isConnectionClosed(), "Connection should be open if no error");
        $this->async->onHeartBeat($this->connection);
        $result = $this->connection->getSentData();
        $this->assertStringMatchesFormat("%d:%s", $result);
        $pos = strpos($result, ":");
        $result = unserialize(substr($result, $pos +1));
        if (version_compare(PHP_VERSION, '7.0.0', '<')) {
            $this->assertInstanceOf(\LogicException::class, $result);
        } else {
            $this->assertInstanceOf(\Throwable::class, $result);
        }
        $this->assertEquals("unserialize(): Error at offset 0 of 3 bytes", $result->getMessage());
    }

    protected function send($message, $useExactMessage)
    {
        if ($useExactMessage) {
            $this->async->onMessage($this->connection, $message);
        }
        $response = $this->connection->getSentData();

        return $response;
    }
}