<?php

namespace ZeusTest;

use PHPUnit_Framework_TestCase;
use React\EventLoop\StreamSelectLoop;
use Zeus\ServerService\Shared\React\ReactServer;

class ReactServerTest extends PHPUnit_Framework_TestCase
{
    private $loop;
    private $server;
    private $port;

    private function createLoop()
    {
        return new StreamSelectLoop();
    }

    public function setUp()
    {
        $this->loop = $this->createLoop();
        $this->server = new ReactServer($this->loop);
        $this->server->listen(0);

        $this->port = $this->server->getPort();
    }

    public function testConnectionWithManyClients()
    {
        $called = 0;
        $this->server->on('connection', function() use (&$called) {
            $called++;
        });

        for($i = 0; $i < 10; $i++) {
            $client[$i] = stream_socket_client('tcp://localhost:' . $this->port);
            $this->loop->tick();
        }

        $this->assertEquals(10, $called, 'Connection should be established 10 times');
    }

    public function testConnectionWithManyClientsWhenListeningByUri()
    {
        $server = new ReactServer($this->loop);
        $server->listenByUri('tcp://localhost:1110');
        $called = 0;
        $server->on('connection', function() use (&$called) {
            $called++;
        });

        for($i = 0; $i < 10; $i++) {
            $client[$i] = stream_socket_client('tcp://localhost:1110');
            $this->loop->tick();
        }

        $this->assertEquals(10, $called, 'Connection should be established 10 times');
    }
}