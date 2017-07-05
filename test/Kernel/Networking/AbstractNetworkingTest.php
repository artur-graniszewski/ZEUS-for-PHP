<?php

namespace ZeusTest\Kernel\Networking;

use PHPUnit_Framework_TestCase;


use Zeus\Kernel\Networking\SocketServer;

abstract class AbstractNetworkingTest extends PHPUnit_Framework_TestCase
{
    /** @var SocketServer[] */
    protected $servers = [];

    protected $clients = [];

    public function tearDown()
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }

        $this->servers = [];

        foreach ($this->clients as $client) {
            if (is_resource($client)) {
                fclose($client);
            }
        }
    }

    /**
     * @param int $port
     * @return SocketServer
     */
    protected function addServer($port)
    {
        $server = new SocketServer($port);
        $this->servers[] = $server;

        return $server;
    }

    /**
     * @param int $port
     * @return resource
     */
    protected function addClient($port)
    {
        $client = stream_socket_client('tcp://localhost:' . $port);
        stream_set_blocking($client, false);
        $this->clients[] = $client;
        return $client;
    }
}