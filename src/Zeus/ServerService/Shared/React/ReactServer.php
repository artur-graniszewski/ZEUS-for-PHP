<?php

namespace Zeus\ServerService\Shared\React;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionException;
use React\Socket\Server;
use React\Stream\Buffer;

/**
 * Class ReactServer
 * @package Zeus\ServerService\Shared\React
 * @internal
 */
class ReactServer extends Server
{
    private $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;

        parent::__construct($loop);
    }

    public function listenByUri($uri)
    {
        $this->master = @stream_socket_server($uri, $errno, $errstr);
        if (false === $this->master) {
            throw new ConnectionException("Could not bind to $uri: $errstr", $errno);
        }
        stream_set_blocking($this->master, 0);

        $this->loop->addReadStream($this->master, function ($master) {
            $newSocket = stream_socket_accept($master);
            if (false === $newSocket) {
                $this->emit('error', [new \RuntimeException('Error accepting new connection')]);

                return;
            }
            $this->handleConnection($newSocket);
        });
    }

    /**
     * @param $socket
     * @return IoConnection
     */
    public function createConnection($socket)
    {
        return new IoConnection($socket, $this->loop, new ReactWritableHighSpeedBuffer($socket, $this->loop));
    }
}
