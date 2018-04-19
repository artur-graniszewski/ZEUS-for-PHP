<?php

namespace ZeusTest\Unit\IO;

use \PHPUnit\Framework\TestCase;
use Zeus\IO\SocketServer;

abstract class AbstractIOTest extends \PHPUnit\Framework\TestCase
{
    /** @var SocketServer[] */
    protected $servers = [];

    protected $clients = [];

    public function setUp()
    {
        $tmpName = __DIR__ . '/../tmp';
        if (!is_dir($tmpName)) {
            mkdir($tmpName);
        }
    }

    public function tearDown()
    {
        foreach ($this->servers as $server) {
            $server->close();
        }

        $this->servers = [];

        foreach ($this->clients as $client) {
            if (is_resource($client)) {
                fclose($client);
            }
        }

        $tmpName = __DIR__ . '/../tmp';
        if (is_dir($tmpName)) {
            $iterator = new \DirectoryIterator($tmpName);
            foreach ($iterator as $entry) {
                if ($entry->isFile()) {
                    unlink($tmpName . "/" . $entry->getFilename());
                }
            }
            rmdir($tmpName);
        }
    }

    protected function getTmpName($fileName)
    {
        $tmpName = __DIR__ . '/../tmp/' . $fileName;

        return $tmpName;
    }

    /**
     * @param int $port
     * @return SocketServer
     */
    protected function addServer($port)
    {
        $server = new SocketServer($port);
        $server->setSoTimeout(1000);
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