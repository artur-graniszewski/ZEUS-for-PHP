<?php

namespace ZeusTest\Services\Async;

use Opis\Closure\SerializableClosure;
use \PHPUnit\Framework\TestCase;
use Zeus\IO\Stream\NetworkStreamInterface;
use Zeus\ServerService\Async\Message\Message;
use ZeusTest\Helpers\SocketTestNetworkStream;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AsyncMessageTest extends \PHPUnit\Framework\TestCase
{
    /** @var NetworkStreamInterface|SocketTestNetworkStream */
    protected $connection;

    /** @var Message */
    protected $async;

    public function setUp()
    {
        $this->connection = new SocketTestNetworkStream(null);
        $this->async = new Message();
        $this->async->onOpen($this->connection);
        $this->connection->setWriteBufferSize(0);
    }

    public function tearDown()
    {
        $this->async->onClose($this->connection);
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

    public function testUnSerializationFailure()
    {
        $result = $this->send("3:aaa\n", true);
        $this->assertEquals("PROCESSING\nCORRUPTED_REQUEST\n", $result);

        $this->assertTrue($this->connection->isConnectionClosed(), "Connection should be closed on error");
    }

    public function testResultOfValidCallback()
    {
        $callback = new SerializableClosure(function() { return 4;});
        $message = serialize($callback);
        $size = strlen($message);
        $result = $this->send("$size:$message\n", true);
        $result = explode("\n", $result);
        $this->assertEquals("PROCESSING", $result[0]);
        $this->async->onHeartBeat($this->connection);
        $this->assertStringMatchesFormat("%d:%s", $result[1]);
        $pos = strpos($result[1], ":");
        $result = unserialize(substr($result[1], $pos +1));
        $this->assertEquals(4, $result);
    }

    public function testErrorOnCorruptedRequest()
    {
        $result = $this->send("3:aaaa\n", true);
        $this->assertEquals("CORRUPTED_REQUEST\n", $result);

        $this->assertTrue($this->connection->isConnectionClosed(), "Connection should be closed on error");
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