<?php

namespace ZeusTest\IO;

use Zeus\IO\Stream\Selector;
use Zeus\IO\Stream\SocketStream;
use Zeus\IO\SocketServer;

class SocketStreamTest extends AbstractIOTest
{
    const TEST_TIMEOUT = 5;

    /** @var SocketServer */
    protected $server;
    protected $port;
    protected $client;

    public function setUp()
    {
        $this->port = rand(7000, 8000);
        $this->server = new SocketServer($this->port);
        $this->server->setSoTimeout(1000);
    }

    public function tearDown()
    {
        $this->server->close();

        if (is_resource($this->client)) {
            fclose($this->client);
        }
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
        $this->client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $this->assertInstanceOf(SocketStream::class, $connection);

        $this->assertTrue($connection->isReadable(), 'Stream should be readable when connected');
        $this->assertTrue($connection->isWritable(), 'Stream should be writable when connected');
        fclose($this->client);
    }

    public function testConnectionDetails()
    {
        $this->client = stream_socket_client('tcp://127.0.0.2:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $this->assertInstanceOf(SocketStream::class, $connection);

        $this->assertEquals(stream_socket_get_name($this->client, false), $connection->getRemoteAddress(), 'Remote address is incorrect');
        $this->assertEquals('127.0.0.2:' . $this->port, $connection->getLocalAddress(), 'Server address is incorrect');
        fclose($this->client);
    }

    public function testConnectionClose()
    {
        $this->client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $this->assertInstanceOf(SocketStream::class, $connection);
        $connection->close();
        $read = @stream_get_contents($this->client);
        $eof = feof($this->client);
        $this->assertEquals("", $read, 'Stream should not contain any message');
        $this->assertEquals(true, $eof, 'Client stream should not be readable when disconnected');
        $this->assertFalse($connection->isReadable(), 'Stream should not be readable when connected');
        $this->assertFalse($connection->isWritable(), 'Stream should not be writable when connected');
        fclose($this->client);
    }

    /**
     * @expectedException \Zeus\IO\Exception\IOException
     * @expectedExceptionMessage Stream already closed
     */
    public function testDoubleClose()
    {
        $this->client = stream_socket_client('tcp://127.0.0.2:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $connection->close();
        $connection->close();
    }

    public function testIsReadable()
    {
        $this->client = stream_socket_client('tcp://127.0.0.2:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $this->assertTrue($connection->isReadable(), 'Connection should be readable');
        $connection->close();
        $this->assertFalse($connection->isReadable(), 'Connection should not be readable after close');
    }

    /**
     * @expectedException \Zeus\IO\Exception\IOException
     * @expectedExceptionMessage Stream is not readable
     */
    public function testExceptionWhenReadingOnClosedConnection()
    {
        $this->client = stream_socket_client('tcp://127.0.0.2:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $connection->close();
        $this->assertFalse($connection->isReadable(), 'Connection should not be readable after close');
        $connection->read();
    }

    /**
     * @expectedException \Zeus\IO\Exception\IOException
     * @expectedExceptionMessage Stream is not writable
     */
    public function testWriteOnClosedConnection()
    {
        $this->client = stream_socket_client('tcp://127.0.0.2:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $connection->close();
        $this->assertFalse($connection->isReadable(), 'Connection should not be readable after close');
        $connection->write("TEST");
    }

    /**
     * @expectedException \Zeus\IO\Exception\IOException
     * @expectedExceptionMessage Stream is not writable
     */
    public function testWriteToDisconnectedClient()
    {
        //$this->markTestIncomplete("Check why PHP fwrite-like functions report that entire string was written on a broken connection");
        $this->client = stream_socket_client('tcp://127.0.0.2:' . $this->port);
        //stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $this->assertTrue($connection->isReadable(), 'Connection should be readable');
        fflush($this->client);
        stream_socket_shutdown($this->client, STREAM_SHUT_RDWR);
        fclose($this->client);
        $this->client = null;
        $connection->write("TEST!");
        $wrote = $connection->flush();
        $this->assertFalse($wrote, 'Flush should have failed');
    }

    /**
     * @param string $dataToSend
     * @dataProvider getTestPayload
     */
    public function testClientSendInChunks($dataToSend)
    {
        $this->client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $this->assertInstanceOf(SocketStream::class, $connection);

        $chunks = str_split($dataToSend, 8192);
        $received = '';
        $time = time();
        do {
            $chunk = array_shift($chunks);
            fwrite($this->client, $chunk);
            fflush($this->client);

            $read = $connection->read();
            $received .= $read;
        } while ($received !== $dataToSend && $time + static::TEST_TIMEOUT > time());

        $this->assertEquals(strlen($dataToSend), strlen($received), 'Server should get the same message length as sent by the client');
        $this->assertEquals($dataToSend, $received, 'Server should get the same message as sent by the client');
        fclose($this->client);
    }

    /**
     * @param string $dataToSend
     * @dataProvider getTestPayload
     */
    public function testClientSendInOnePiece($dataToSend)
    {
        $this->client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $this->assertInstanceOf(SocketStream::class, $connection);
        $received = '';
        $time = time();
        fwrite($this->client, $dataToSend);
        fflush($this->client);
        do {
            $read = $connection->read();
            $received .= $read;
        } while ($received !== $dataToSend && $time + static::TEST_TIMEOUT > time());

        $this->assertEquals(strlen($dataToSend), strlen($received), 'Server should get the same message length as sent by the client');
        $this->assertEquals($dataToSend, $received, 'Server should get the same message as sent by the client');
        fclose($this->client);
    }

    /**
     * @param string $dataToSend
     * @dataProvider getTestPayload
     */
    public function testServerSendInChunks($dataToSend)
    {
        $this->client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();

        $chunks = str_split($dataToSend, 8192);
        $received = '';
        $time = time();
        do {
            $chunk = (string) array_shift($chunks);
            $connection->write($chunk);
            $connection->flush();

            $read = stream_get_contents($this->client);
            $received .= $read;
        } while ($read !== false && $received !== $dataToSend && $time + static::TEST_TIMEOUT > time());

        $this->assertEquals($dataToSend, $received, 'Server should get the same message as sent by the client');
        fclose($this->client);
    }

    /**
     * @param string $dataToSend
     * @dataProvider getTestPayload
     */
    public function testServerSendInOnePiece($dataToSend)
    {
        $this->client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $received = '';
        $time = time();
        $connection->write($dataToSend);
        $connection->flush();
        do {
            $read = stream_get_contents($this->client);
            $received .= $read;
        } while ($read !== false && $received !== $dataToSend && $time + static::TEST_TIMEOUT > time());

        $this->assertEquals($dataToSend, $received, 'Server should get the same message as sent by the client');
        fclose($this->client);
    }

    public function testServerReadWhenDisconnected()
    {
        $this->client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($this->client, true);
        $connection = $this->server->accept();
        fclose($this->client);
        $output = $connection->read();
        $this->assertEmpty($output, 'Nothing should be read from stream');
    }

    /**
     * @param string $dataToSend
     * @dataProvider getTestPayload
     */
    public function testWriteBuffering($dataToSend)
    {
        $this->client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $this->assertInstanceOf(SocketStream::class, $connection);
        $connection->write($dataToSend);

        $received = stream_get_contents($this->client, strlen($dataToSend));
        if (strlen($dataToSend) < $connection::DEFAULT_WRITE_BUFFER_SIZE + 1) {
            $this->assertEquals(0, strlen($received), 'Data should be stored in buffer if its not full');
        } else {
            $time = time();
            do {
                $read = stream_get_contents($this->client);
                $received .= $read;
            } while ($read !== false && $received === '' && $time + static::TEST_TIMEOUT > time());
            $this->assertGreaterThan(0, strlen($received), 'Buffer should be flushed when full');
        }

        fclose($this->client);
    }

    /**
     * @param string $dataToSend
     * @dataProvider getTestPayload
     */
    public function testWriteWithoutBuffering($dataToSend)
    {
        $this->client = stream_socket_client('tcp://localhost:' . $this->port);
        stream_set_blocking($this->client, false);
        $connection = $this->server->accept();
        $this->assertInstanceOf(SocketStream::class, $connection);
        $connection->setWriteBufferSize(0);
        $connection->write($dataToSend);

        $received = stream_get_contents($this->client, strlen($dataToSend));

        $time = time();
        do {
            $read = stream_get_contents($this->client);
            $received .= $read;
        } while ($read !== false && $received === '' && $time + static::TEST_TIMEOUT > time());
        $this->assertGreaterThan(0, strlen($received), 'Buffer should be flushed when full');

        fclose($this->client);
    }
}