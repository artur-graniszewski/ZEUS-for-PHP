<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use Zeus\ServerService\Async\Config;
use Zeus\Kernel\Networking\SocketStream;
use Zeus\Kernel\Networking\SocketServer;

class SocketStreamTest extends PHPUnit_Framework_TestCase
{
    const TEST_TIMEOUT = 5;

    /** @var SocketServer */
    protected $server;
    protected $port;

    public function setUp()
    {
        $config = new Config();
        $this->port = 7777;
        $config->setListenPort($this->port);
        $config->setListenAddress('0.0.0.0');
        $this->server = new SocketServer($config);
        $this->server->createServer();
    }

    public function tearDown()
    {
        $this->server->stop();
    }

    public function getTestPayload()
    {
        return [
            ['TEST STRING'],
            ["TEST\nMULTILINE\nSTRING"],
            ["TEST\0NULLABLE\0STRING"],
            [str_pad("1", 1023) . "!"],
            [str_pad("2", 2047) . "!"],
            [str_pad("3", 8191) . "!"],
            [str_pad("4", 16383) . "!"],
            [str_pad("5", 32767) . "!"],
            [str_pad("6", 65535) . "!"],
            [str_pad("7", 131071) . "!"],
        ];
    }

    public function testConnection()
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, false);
        $connection = $this->server->listen(1);
        $this->assertInstanceOf(SocketStream::class, $connection);

        $this->assertTrue($connection->isReadable(), 'Stream should be readable when connected');
        $this->assertTrue($connection->isWritable(), 'Stream should be writable when connected');
        fclose($client);
    }

    public function testConnectionDetails()
    {
        $client = stream_socket_client('tcp://127.0.0.2:' . $this->port);
        stream_set_blocking($client, false);
        $connection = $this->server->listen(1);
        $this->assertInstanceOf(SocketStream::class, $connection);

        $this->assertEquals(stream_socket_get_name($client, false), $connection->getRemoteAddress(), 'Remote address is incorrect');
        $this->assertEquals('127.0.0.2:' . $this->port, $connection->getServerAddress(), 'Server address is incorrect');
        fclose($client);
    }

    public function testConnectionClose()
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, false);
        $connection = $this->server->listen(1);
        $this->assertInstanceOf(SocketStream::class, $connection);
        $connection->close();
        $read = @stream_get_contents($client);
        $eof = feof($client);
        $this->assertEquals("", $read, 'Stream should not contain any message');
        $this->assertEquals(true, $eof, 'Client stream should not be readable when disconnected');
        $this->assertFalse($connection->isReadable(), 'Stream should not be readable when connected');
        $this->assertFalse($connection->isWritable(), 'Stream should not be writable when connected');
        fclose($client);
    }

    /**
     * @param string $dataToSend
     * @dataProvider getTestPayload
     */
    public function testConnectionEnd($dataToSend)
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, false);
        $connection = $this->server->listen(1);
        $this->assertInstanceOf(SocketStream::class, $connection);
        $connection->end($dataToSend);
        $read = stream_socket_recvfrom($client, strlen($dataToSend));
        $this->assertEquals($dataToSend, $read, 'Stream should contain ending message');
        fclose($client);
    }

    /**
     * @param string $dataToSend
     * @dataProvider getTestPayload
     */
    public function testClientSendInChunks($dataToSend)
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, false);
        $connection = $this->server->listen(1);
        $this->assertInstanceOf(SocketStream::class, $connection);

        $chunks = str_split($dataToSend, 8192);
        $received = '';
        $time = time();
        do {
            $chunk = array_shift($chunks);
            fwrite($client, $chunk);
            fflush($client);

            $read = $connection->read();
            $received .= $read;
        } while ($read !== false && $received !== $dataToSend && $time + static::TEST_TIMEOUT > time());
        
        $this->assertEquals($dataToSend, $received, 'Server should get the same message as sent by the client');
        fclose($client);
    }

    /**
     * @param string $dataToSend
     * @dataProvider getTestPayload
     */
    public function testClientSendInOnePiece($dataToSend)
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, false);
        $connection = $this->server->listen(1);
        $this->assertInstanceOf(SocketStream::class, $connection);
        $received = '';
        $time = time();
        fwrite($client, $dataToSend);
        fflush($client);
        do {
            $read = $connection->read();
            $received .= $read;
        } while ($read !== false && $received !== $dataToSend && $time + static::TEST_TIMEOUT > time());

        $this->assertEquals($dataToSend, $received, 'Server should get the same message as sent by the client');
        fclose($client);
    }

    /**
     * @param string $dataToSend
     * @dataProvider getTestPayload
     */
    public function testServerSendInChunks($dataToSend)
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, false);
        $connection = $this->server->listen(1);

        $chunks = str_split($dataToSend, 8192);
        $received = '';
        $time = time();
        do {
            $chunk = array_shift($chunks);
            $connection->write($chunk);
            $connection->flush();

            $read = stream_get_contents($client);
            $received .= $read;
        } while ($read !== false && $received !== $dataToSend && $time + static::TEST_TIMEOUT > time());

        $this->assertEquals($dataToSend, $received, 'Server should get the same message as sent by the client');
        fclose($client);
    }

    /**
     * @param string $dataToSend
     * @dataProvider getTestPayload
     */
    public function testServerSendInOnePiece($dataToSend)
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, false);
        $connection = $this->server->listen(1);
        $received = '';
        $time = time();
        $connection->write($dataToSend);
        $connection->flush();
        do {
            $read = stream_get_contents($client);
            $received .= $read;
        } while ($read !== false && $received !== $dataToSend && $time + static::TEST_TIMEOUT > time());

        $this->assertEquals($dataToSend, $received, 'Server should get the same message as sent by the client');
        fclose($client);
    }

    public function testServerReadWhenDisconnected()
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, true);
        $connection = $this->server->listen(1);
        fclose($client);
        $connection->read();
        $this->assertFalse($connection->isReadable(), 'Stream should not be readable when disconnected');
        $this->assertFalse($connection->isWritable(), 'Stream should not be writable when disconnected');
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Stream is not readable
     */
    public function testServerSelectThrowsExceptionWhenDisconnected()
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, true);
        $connection = $this->server->listen(1);
        fclose($client);
        $connection->read();
        $connection->select(1);
    }

    public function testServerSelectReturnsTrueWhenDisconnected()
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, true);
        $connection = $this->server->listen(1);
        fclose($client);
        $result = $connection->select(1);
        $this->assertTrue($result, 'Select should report stream as readable until read is performed on disconnected client');
    }

    /**
     * @param string $dataToSend
     * @dataProvider getTestPayload
     */
    public function testServerBuffering($dataToSend)
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($client, false);
        $connection = $this->server->listen(1);
        $this->assertInstanceOf(SocketStream::class, $connection);
        $connection->write($dataToSend);

        $received = stream_get_contents($client, strlen($dataToSend));
        if (strlen($dataToSend) < $connection::DEFAULT_WRITE_BUFFER_SIZE + 1) {
            $this->assertEquals(0, strlen($received), 'Data should be stored in buffer if its not full');
        } else {
            $time = time();
            do {
                $read = stream_get_contents($client);
                $received .= $read;
            } while ($read !== false && $received === '' && $time + static::TEST_TIMEOUT > time());
            $this->assertGreaterThan(0, strlen($received), 'Buffer should be flushed when full');
        }

        fclose($client);
    }
}