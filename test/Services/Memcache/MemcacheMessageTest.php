<?php

namespace ZeusTest\Services\Memcache;

use PHPUnit_Framework_TestCase;
use Zend\Cache\Storage\Adapter\Apcu;
use Zend\Cache\Storage\Adapter\Filesystem;
use Zend\Cache\Storage\StorageInterface;
use Zeus\Module;
use Zeus\ServerService\Memcache\Message\Message;
use Zeus\ServerService\Shared\React\ConnectionInterface;
use ZeusTest\Helpers\TestConnection;

class MemcacheMessageTest extends PHPUnit_Framework_TestCase
{
    /** @var ConnectionInterface */
    protected $connection;

    /** @var StorageInterface */
    protected $memcache;

    protected function getTmpDir()
    {
        $tmpDir = __DIR__ . '/../../tmp/';

        if (!file_exists($tmpDir)) {
            mkdir($tmpDir);
        }
        return $tmpDir;
    }

    public function tearDown()
    {
        $cache = new Filesystem(['cache_dir' => $this->getTmpDir()]);
        $cache->flush();

        rmdir(__DIR__ . '/../../tmp/');
        parent::tearDown();
    }

    public function setUp()
    {
        try {
            $filesystem1 = new Filesystem(['cache_dir' => $this->getTmpDir()]);
            $filesystem2 = new Filesystem(['cache_dir' => $this->getTmpDir()]);
        } catch (\Exception $ex) {
            $this->markTestSkipped('Could not use Filesystem adapter: ' . $ex->getMessage());

            return;
        }
        $this->connection = new TestConnection();
        $this->memcache = new Message($filesystem1, $filesystem2);
        $this->memcache->onOpen($this->connection);
    }

    public function testQuitCommand()
    {
        $this->send("quit\r\n");

        $this->assertTrue($this->connection->isConnectionClosed(), "Quit command should disconnect server");
    }

    public function noReplyProvider()
    {
        return [
            [' noreply', ''],
            ['', "STORED\r\n"],
        ];
    }

    /**
     * @param string $noReplyParam
     * @param string $expectedStatus
     * @dataProvider noReplyProvider
     */
    public function testSetCommand($noReplyParam, $expectedStatus)
    {
        $testConnection = new TestConnection();
        $ttl = time() + 5;
        $value = str_pad('!', rand(3, 5), 'A', STR_PAD_RIGHT) . '#';
        $length = strlen($value);
        $result = $this->send("set testkey2 12121212 $ttl $length$noReplyParam\r\n$value\r\n");
        $this->assertFalse($testConnection->isConnectionClosed(), "Connection should be kept alive");
        $this->assertEquals($expectedStatus, $result);

        $result = $this->send("get testkey2 testkey3\r\n");
        $this->assertStringStartsWith("VALUE testkey2 12121212 $length\r\n", $result);
        $this->assertStringEndsWith("\r\nEND\r\n", $result);

        $result = $this->send("gets testkey2 testkey3\r\n");
        $this->assertStringStartsWith("VALUE testkey2 12121212 $length ", $result);
        $this->assertStringEndsWith("\r\nEND\r\n", $result);
    }

    public function testFetchCommand()
    {
        $result = $this->send("get testkey testkey3\r\n");

        $this->assertFalse($this->connection->isConnectionClosed(), "Connection should be kept alive");
        $this->assertEquals("END\r\n", $result);
    }

    public function testCasCommand()
    {
        $ttl = time() + 5;
        $value = str_pad('!', rand(3, 5), 'B', STR_PAD_RIGHT) . '#';
        $length = strlen($value);
        $this->send("set testkey4 12121212 $ttl $length\r\n$value\r\n");

        $response = $this->send("gets testkey4 testkey3\r\n");

        $found = preg_match("~^VALUE testkey4 12121212 $length ([0-9]+)\r\n([^\r\n]+)\r\nEND\r\n~", $response, $matches);
        $this->assertTrue((bool) $found, 'Returned value should contain CAS token: ' . $response);
        $cas = $matches[1];

        $response = $this->send("cas testkey4 12121212 $ttl $length $cas\r\n$value\r\n");
        $this->assertEquals("STORED\r\n", $response);

        $value = str_pad('!', rand(3, 5), 'B', STR_PAD_RIGHT) . '#';
        $length = strlen($value);
        $response = $this->send("set testkey4 12121212 $ttl $length\r\n$value\r\n");
        $this->assertEquals("STORED\r\n", $response);

        $response = $this->send("cas testkey4 12121212 $ttl $length $cas\r\n$value\r\n");
        $this->assertEquals("EXISTS\r\n", $response);
    }

    public function testDeleteCommand()
    {
        $ttl = time() + 5;
        $value = str_pad('!', rand(3, 5), 'C', STR_PAD_RIGHT) . '#';
        $length = strlen($value);
        $response = $this->send("set testkey6 22222 $ttl $length\r\n$value\r\n");
        $this->assertEquals("STORED\r\n", $response);

        $response = $this->send("get testkey6\r\n");
        $this->assertStringStartsWith("VALUE testkey6 22222 $length\r\n", $response);
        $this->assertStringEndsWith("\r\nEND\r\n", $response);

        $response = $this->send("delete testkey6\r\n");
        $this->assertEquals("DELETED\r\n", $response);

        $response = $this->send("get testkey6\r\n");
        $this->assertEquals("END\r\n", $response);

        $response = $this->send("delete testkey6\r\n");
        $this->assertEquals("NOT_FOUND\r\n", $response);
    }

    public function testFlushCommand()
    {
        $ttl = time() + 5;
        $value = str_pad('!', rand(3, 5), 'C', STR_PAD_RIGHT) . '#';
        $length = strlen($value);
        $response = $this->send("set testkey6 22222 $ttl $length\r\n$value\r\n");
        $this->assertEquals("STORED\r\n", $response);

        $response = $this->send("get testkey6\r\n");
        $this->assertStringStartsWith("VALUE testkey6 22222 $length\r\n", $response);
        $this->assertStringEndsWith("\r\nEND\r\n", $response);

        $response = $this->send("flush_all\r\n");
        $this->assertEquals("OK\r\n", $response);

        $response = $this->send("get testkey6\r\n");
        $this->assertEquals("END\r\n", $response);
    }

    public function testVersionCommand()
    {
        $version = Module::MODULE_VERSION;
        $response = $this->send("version\r\n");
        $this->assertEquals("VERSION $version\r\n", $response);
    }

    public function testFlushBeforeCommand()
    {
        $ttl = time() + 5;
        $value = str_pad('!', rand(3, 5), 'C', STR_PAD_RIGHT) . '#';
        $length = strlen($value);
        $response = $this->send("set testkey6 22222 $ttl $length\r\n$value\r\n");
        $this->assertEquals("STORED\r\n", $response);

        $response = $this->send("get testkey6\r\n");
        $this->assertStringStartsWith("VALUE testkey6 22222 $length\r\n", $response);
        $this->assertStringEndsWith("\r\nEND\r\n", $response);

        $response = $this->send("flush_all 1111\r\n");
        $this->assertEquals("OK\r\n", $response);

        $response = $this->send("get testkey6\r\n");
        $this->assertEquals("END\r\n", $response);
    }

    public function testMathCommands()
    {
        $ttl = time() + 5;

        $response = $this->send("set testkey7 1 $ttl 1\r\n1\r\n");
        $this->assertEquals("STORED\r\n", $response);
        $response = $this->send("get testkey7\r\n");
        $this->assertEquals("VALUE testkey7 1 1\r\n1\r\nEND\r\n", $response);

        $response = $this->send("incr testkey7 2\r\n");
        $this->assertEquals("3\r\n", $response);

        $response = $this->send("incr testkey7 5\r\n");
        $this->assertEquals("8\r\n", $response);

        $response = $this->send("decr testkey7 3\r\n");
        $this->assertEquals("5\r\n", $response);

        $response = $this->send("decr testkey7 10\r\n");
        $this->assertEquals("0\r\n", $response);
    }

    public function testMathCommandsOnNonNumericValue()
    {
        $ttl = time() + 5;

        $response = $this->send("set testkey8 1 $ttl 1\r\na\r\n");
        $this->assertEquals("STORED\r\n", $response);
        $response = $this->send("get testkey8\r\n");
        $this->assertEquals("VALUE testkey8 1 1\r\na\r\nEND\r\n", $response);

        $response = $this->send("incr testkey8 2\r\n");
        $this->assertEquals("ERROR\r\n", $response);
    }

    public function testConcatenationCommands()
    {
        $ttl = time() + 5;

        $response = $this->send("set testkey8 2 $ttl 3\r\n456\r\n");
        $this->assertEquals("STORED\r\n", $response);
        $response = $this->send("get testkey8\r\n");
        $this->assertEquals("VALUE testkey8 2 3\r\n456\r\nEND\r\n", $response);

        $response = $this->send("append testkey8 3\r\n789\r\n");
        $this->assertEquals("STORED\r\n", $response);
        $response = $this->send("get testkey8\r\n");
        $this->assertEquals("VALUE testkey8 2 6\r\n456789\r\nEND\r\n", $response);

        $response = $this->send("prepend testkey8 3\r\n123\r\n");
        $this->assertEquals("STORED\r\n", $response);
        $response = $this->send("get testkey8\r\n");
        $this->assertEquals("VALUE testkey8 2 9\r\n123456789\r\nEND\r\n", $response);
    }

    public function testTouchCommand()
    {
        $ttl = time() + 5;
        $response = $this->send("touch testkey11 10\r\n");
        $this->assertEquals("NOT_FOUND\r\n", $response);
        $response = $this->send("set testkey11 7 $ttl 3\r\n456\r\n");
        $this->assertEquals("STORED\r\n", $response);
        $response = $this->send("touch testkey11 10\r\n");
        $this->assertEquals("TOUCHED\r\n", $response);
    }

    public function testStatsCommands()
    {
        $response = $this->send("stats\r\n");
        $this->assertContains("STAT bytes ", $response);
    }

    protected function send($message)
    {
        $this->memcache->onMessage($this->connection, $message);
        $response = $this->connection->getSentData();

        return $response;
    }

}